<?php

namespace App\Support;

use App\Models\BidUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Maps “last scraped” semantics onto BIDURL (END_TIME on Oracle, last_scraped_at on MySQL). */
final class BidUrlScrapeMarker
{
	private static ?string $resolvedColumn = null;

	private static bool $resolved = false;

	public static function resetCache(): void
	{
		self::$resolvedColumn = null;
		self::$resolved = false;
	}

	public static function table(): string
	{
		return (new BidUrl())->getTable();
	}

	public static function lastScrapedColumn(): ?string
	{
		if (self::$resolved) {
			return self::$resolvedColumn !== '' ? self::$resolvedColumn : null;
		}

		self::$resolved = true;

		$configured = trim((string) config('scraper.bid_url_last_scraped_column', ''));
		$candidates = array_values(array_filter(array_unique([
			$configured !== '' ? $configured : null,
			'last_scraped_at',
			'END_TIME',
		])));

		$table = self::table();
		foreach ($candidates as $col) {
			try {
				if (Schema::hasColumn($table, $col)) {
					self::$resolvedColumn = $col;

					return $col;
				}
			} catch (\Throwable) {
				continue;
			}
		}

		self::$resolvedColumn = '';

		return null;
	}

	public static function hasDedicatedLastScrapedColumn(): bool
	{
		$col = self::lastScrapedColumn();

		return $col !== null && strcasecmp($col, 'last_scraped_at') === 0;
	}

	public static function hasScrapedBidUrls(): bool
	{
		$query = BidUrl::query();
		$col = self::lastScrapedColumn();
		if ($col === null) {
			return $query->exists();
		}

		return $query->whereNotNull($col)->exists();
	}

	public static function scopeBidListingToScrapedBidUrls(Builder $outer): Builder
	{
		$urlPk = (new BidUrl())->getKeyName();
		$inner = BidUrl::query()->select($urlPk);
		$col = self::lastScrapedColumn();
		if ($col !== null) {
			$inner->whereNotNull($col);
		}

		return $outer->whereIn('BID_URL_ID', $inner);
	}

	public static function readFromAttributes(array $attributes): ?Carbon
	{
		$col = self::lastScrapedColumn();
		if ($col !== null) {
			foreach ($attributes as $key => $value) {
				if (strcasecmp((string) $key, $col) === 0 && $value !== null && $value !== '') {
					return $value instanceof Carbon ? $value : Carbon::parse($value);
				}
			}
		}

		foreach (['last_scraped_at', 'end_time', 'END_TIME'] as $fallback) {
			foreach ($attributes as $key => $value) {
				if (strcasecmp((string) $key, $fallback) === 0 && $value !== null && $value !== '') {
					return $value instanceof Carbon ? $value : Carbon::parse($value);
				}
			}
		}

		return null;
	}

	public static function readFromModel(BidUrl $bidUrl): ?Carbon
	{
		return self::readFromAttributes($bidUrl->getAttributes());
	}

	/** @return array<string, mixed> */
	public static function finishScrapeUpdateAttributes(Carbon $start, Carbon $end): array
	{
		$attrs = [
			'start_time' => $start,
			'end_time' => $end,
		];

		if (self::hasDedicatedLastScrapedColumn()) {
			$attrs['last_scraped_at'] = $end;
		}

		return $attrs;
	}

	public static function applyFinishScrapeTimes(BidUrl $bidUrl, Carbon $start, Carbon $end): void
	{
		$bidUrl->start_time = $start;
		$bidUrl->end_time = $end;
		if (self::hasDedicatedLastScrapedColumn()) {
			$bidUrl->last_scraped_at = $end;
		}
	}

	public static function applyManualLastScraped(BidUrl $bidUrl, Carbon $at): void
	{
		$col = self::lastScrapedColumn();
		if ($col === null) {
			$bidUrl->end_time = $at;

			return;
		}

		if (strcasecmp($col, 'last_scraped_at') === 0) {
			$bidUrl->last_scraped_at = $at;

			return;
		}

		$bidUrl->setAttribute($col, $at);
	}

	public static function clearManualLastScraped(BidUrl $bidUrl): void
	{
		$col = self::lastScrapedColumn();
		if ($col === null) {
			$bidUrl->end_time = null;

			return;
		}

		$bidUrl->setAttribute($col, null);
	}

	/**
	 * Map failed-URL last_scraped_at onto BIDURL columns (END_TIME on Oracle).
	 *
	 * @return array<string, mixed>
	 */
	public static function restoreLastScrapedAttributes(?Carbon $lastScraped, ?Carbon $endTime = null): array
	{
		if ($lastScraped === null) {
			return [];
		}

		if (self::hasDedicatedLastScrapedColumn()) {
			return ['last_scraped_at' => $lastScraped];
		}

		if ($endTime !== null) {
			return [];
		}

		$col = self::lastScrapedColumn();
		if ($col !== null && strcasecmp($col, 'last_scraped_at') !== 0) {
			$key = strcasecmp($col, 'END_TIME') === 0 ? 'end_time' : $col;

			return [$key => $lastScraped];
		}

		return ['end_time' => $lastScraped];
	}
}
