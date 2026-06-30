<?php

namespace App\Support;

use App\Models\BidUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Scrape group labels on scraper-managed BID_URL rows. */
final class BidUrlScrapeGroup
{
	public const DEFAULT = 'Test';

	private static ?string $physicalColumn = null;

	private static bool $physicalColumnResolved = false;

	public static function resetCache(): void
	{
		self::$physicalColumn = null;
		self::$physicalColumnResolved = false;
	}

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
		$table = (new BidUrl())->getTable();
		$groups = [];

		foreach (self::distinctGroupFetchStrategies($table, $col) as $fetch) {
			try {
				$groups = $fetch();
				if ($groups !== []) {
					break;
				}
			} catch (\Throwable $e) {
				Log::warning('Scrape group distinct query failed', [
					'table' => $table,
					'column' => $col,
					'error' => $e->getMessage(),
				]);
			}
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

	public static function readFromAttributes(array $attributes): ?string
	{
		if (!self::hasColumn()) {
			foreach ($attributes as $key => $value) {
				if (strcasecmp((string) $key, 'scrape_group') === 0) {
					$trimmed = trim((string) ($value ?? ''));
					return $trimmed !== '' ? $trimmed : null;
				}
			}

			return null;
		}

		$col = self::column();
		foreach ($attributes as $key => $value) {
			if (strcasecmp((string) $key, $col) === 0 || strcasecmp((string) $key, 'scrape_group') === 0) {
				$trimmed = trim((string) ($value ?? ''));
				return $trimmed !== '' ? $trimmed : null;
			}
		}

		return null;
	}

	public static function readFromModel(BidUrl $bidUrl): ?string
	{
		return self::readFromAttributes($bidUrl->getAttributes());
	}

	/** @return list<callable(): list<string>> */
	private static function distinctGroupFetchStrategies(string $table, string $col): array
	{
		return [
			fn () => self::fetchDistinctGroupValuesFromQueryBuilder($table, $col),
			fn () => self::fetchDistinctGroupValuesFromModels(),
		];
	}

	/** @return list<string> */
	private static function fetchDistinctGroupValuesFromQueryBuilder(string $table, string $col): array
	{
		return DB::table($table)
			->select($col)
			->whereNotNull($col)
			->distinct()
			->orderBy($col)
			->pluck($col)
			->map(fn ($value) => trim((string) $value))
			->filter(fn (string $value) => $value !== '')
			->values()
			->all();
	}

	/** @return list<string> */
	private static function fetchDistinctGroupValuesFromModels(): array
	{
		$col = self::column();
		$key = (new BidUrl())->getKeyName();
		$seen = [];

		BidUrl::query()
			->select([$key, $col])
			->orderBy($key)
			->chunkById(500, function ($rows) use ($col, &$seen) {
				foreach ($rows as $row) {
					$value = trim((string) (self::readFromModel($row) ?? ''));
					if ($value !== '') {
						$seen[$value] = true;
					}
				}
			}, $key);

		$groups = array_keys($seen);
		sort($groups, SORT_STRING | SORT_FLAG_CASE);

		return $groups;
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

		$table = (new BidUrl())->getTable();

		try {
			foreach ($candidates as $candidate) {
				if ($candidate !== '' && Schema::hasColumn($table, $candidate)) {
					self::$physicalColumn = $candidate;

					return self::$physicalColumn;
				}
			}
		} catch (\Throwable) {
		}

		self::$physicalColumn = self::probePhysicalColumn($table, $candidates);

		return self::$physicalColumn;
	}

	/** @param  list<string>  $candidates */
	private static function probePhysicalColumn(string $table, array $candidates): ?string
	{
		try {
			$row = DB::table($table)->limit(1)->first();
			if ($row !== null) {
				foreach ($candidates as $candidate) {
					foreach (array_keys((array) $row) as $key) {
						if (strcasecmp((string) $key, (string) $candidate) === 0) {
							return (string) $key;
						}
					}
				}
			}
		} catch (\Throwable) {
		}

		foreach ($candidates as $candidate) {
			if ($candidate === '') {
				continue;
			}

			try {
				DB::table($table)->select($candidate)->limit(1)->get();

				return $candidate;
			} catch (\Throwable) {
				continue;
			}
		}

		return null;
	}
}
