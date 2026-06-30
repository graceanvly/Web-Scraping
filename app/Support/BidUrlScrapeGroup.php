<?php

namespace App\Support;

use App\Models\BidUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Scrape group labels on scraper-managed BID_URL rows. */
final class BidUrlScrapeGroup
{
	public const DEFAULT = 'Test';

	private static ?string $physicalColumn = null;

	private static bool $physicalColumnResolved = false;

	public static function default(): string
	{
		return (string) config('scraper.bid_url_scrape_group_default', self::DEFAULT);
	}

	public static function column(): string
	{
		$physical = self::resolvePhysicalColumn();
		if ($physical !== null) {
			return $physical;
		}

		return (string) config('scraper.bid_url_scrape_group_column', 'scrape_group');
	}

	public static function hasColumn(): bool
	{
		return self::resolvePhysicalColumn() !== null;
	}

	public static function applyFilter(Builder $query, ?string $group): Builder
	{
		$group = trim((string) ($group ?? ''));
		if ($group === '' || !self::hasColumn()) {
			return $query;
		}

		return $query->where(self::column(), $group);
	}

	public static function queryForScrape(?string $group): Builder
	{
		$query = BidUrl::query()->orderBy((new BidUrl())->getKeyName());

		return self::applyFilter($query, $group);
	}

	/** @return list<string> */
	public static function distinctGroups(): array
	{
		if (!self::hasColumn()) {
			return [self::default()];
		}

		$col = self::column();
		$groups = [];

		try {
			$groups = BidUrl::query()
				->select($col)
				->whereNotNull($col)
				->where($col, '<>', '')
				->distinct()
				->orderBy($col)
				->pluck($col)
				->map(fn ($value) => trim((string) $value))
				->filter(fn (string $value) => $value !== '')
				->values()
				->all();
		} catch (\Throwable $e) {
			Log::warning('Could not load scrape groups from bid_url', ['error' => $e->getMessage()]);

			return [self::default()];
		}

		return self::mergeGroupNames($groups);
	}

	/**
	 * @param  list<string>  $groups
	 * @return list<string>
	 */
	public static function mergeGroupNames(array $groups): array
	{
		$normalized = [];
		foreach ($groups as $value) {
			$trimmed = trim((string) $value);
			if ($trimmed !== '') {
				$normalized[] = $trimmed;
			}
		}

		$merged = array_values(array_unique(array_merge([self::default()], $normalized)));
		sort($merged, SORT_STRING | SORT_FLAG_CASE);

		return $merged;
	}

	private static function resolvePhysicalColumn(): ?string
	{
		if (self::$physicalColumnResolved) {
			return self::$physicalColumn;
		}

		self::$physicalColumnResolved = true;
		self::$physicalColumn = null;

		$configured = (string) config('scraper.bid_url_scrape_group_column', 'scrape_group');
		$candidates = array_values(array_unique([
			$configured,
			strtolower($configured),
			strtoupper($configured),
			'scrape_group',
			'SCRAPE_GROUP',
		]));

		try {
			$table = (new BidUrl())->getTable();
			foreach ($candidates as $candidate) {
				if ($candidate !== '' && Schema::hasColumn($table, $candidate)) {
					self::$physicalColumn = $candidate;

					return self::$physicalColumn;
				}
			}
		} catch (\Throwable) {
			self::$physicalColumn = null;
		}

		return self::$physicalColumn;
	}
}
