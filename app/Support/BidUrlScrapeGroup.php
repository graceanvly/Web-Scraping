<?php

namespace App\Support;

use App\Models\BidUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/** Scrape group labels on scraper-managed BID_URL rows. */
final class BidUrlScrapeGroup
{
	public const DEFAULT = 'Test';

	public static function default(): string
	{
		return (string) config('scraper.bid_url_scrape_group_default', self::DEFAULT);
	}

	public static function column(): string
	{
		return (string) config('scraper.bid_url_scrape_group_column', 'scrape_group');
	}

	public static function hasColumn(): bool
	{
		static $resolved = null;
		if ($resolved !== null) {
			return $resolved;
		}

		try {
			$resolved = Schema::hasColumn((new BidUrl())->getTable(), self::column());
		} catch (\Throwable) {
			$resolved = false;
		}

		return $resolved;
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

		try {
			$groups = BidUrl::query()
				->whereNotNull($col)
				->where($col, '!=', '')
				->distinct()
				->orderBy($col)
				->pluck($col)
				->map(fn ($value) => trim((string) $value))
				->filter(fn (string $value) => $value !== '')
				->values()
				->all();
		} catch (\Throwable) {
			return [self::default()];
		}

		if ($groups === []) {
			return [self::default()];
		}

		return $groups;
	}
}
