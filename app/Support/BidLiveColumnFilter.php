<?php

namespace App\Support;

use App\Models\Bid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drop attribute keys that are not real columns on the live bid table (Oracle vs MySQL).
 * Preserves app attribute key casing for Eloquent fill() while recognizing Oracle DDL names.
 */
final class BidLiveColumnFilter
{
	/**
	 * Production Oracle ODS.BID columns (merged with Schema::getColumnListing when introspection is incomplete).
	 *
	 * @var list<string>
	 */
	private const CANONICAL_ORACLE_BID_COLUMNS = [
		'ID',
		'TITLE',
		'DESCRIPTION',
		'EMAIL',
		'URL',
		'CREATED',
		'ENDDATE',
		'CATEGORYID',
		'ENTITYID',
		'SUBSCRIPTIONTYPEID',
		'USERID',
		'THIRD_PARTY_IDENTIFIER',
		'SOLICITATIONNUMBER',
		'FEDDATE',
		'SETASIDECODEID',
		'NAICSCODE',
		'BID_URL_ID',
		'INLINEURL',
		'NEEDS_REVIEW',
		'SOURCE_ID',
		'STATEID',
		'LAST_MODIFIED',
		'CATEGORY_ALIAS_ID',
		'ENTITY_ALIAS_ID',
		'COUNTRY_ID',
		'UNDERREVIEW',
		'NAICSCODE_INT',
		'NSN',
	];

	/** App / MySQL attribute name => live column names (first match in schema wins). */
	private const APP_COLUMN_ALIASES = [
		'SOLICIATIONNUMBER' => ['SOLICITATIONNUMBER', 'SOLICIATIONNUMBER'],
	];

	/** @var array<string, true>|null null when schema unreadable */
	private static ?array $allowedLower = null;

	/** @var array<string, string>|null lower => actual column name */
	private static ?array $columnMapLower = null;

	/** @var bool */
	private static bool $schemaResolved = false;

	public static function filter(array $attributes): array
	{
		$map = self::columnMap();
		if ($map === null) {
			return $attributes;
		}

		$out = [];
		foreach ($attributes as $key => $value) {
			if (self::matchesLiveColumn((string) $key)) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	/**
	 * Filter unknown columns and map app keys to real live table column names for insert/update.
	 *
	 * @param  array<string, mixed>  $attributes
	 * @return array<string, mixed>
	 */
	public static function filterForWrite(array $attributes): array
	{
		return self::normalizeAttributeKeys(self::filter($attributes));
	}

	/**
	 * @param  array<string, mixed>  $attributes
	 * @return array<string, mixed>
	 */
	public static function normalizeAttributeKeys(array $attributes): array
	{
		$out = [];
		foreach ($attributes as $key => $value) {
			$liveKey = self::liveAttributeKeyFor((string) $key);
			$out[$liveKey] = $value;
		}

		return $out;
	}

	public static function resolveColumnName(string $preferred): ?string
	{
		$map = self::columnMap();
		if ($map === null) {
			return $preferred;
		}

		foreach (self::liveColumnCandidates($preferred) as $lower) {
			if (isset($map[$lower])) {
				return $map[$lower];
			}
		}

		return null;
	}

	public static function hasColumn(string $preferred): bool
	{
		$map = self::columnMap();
		if ($map === null) {
			return true;
		}

		return self::matchesLiveColumn($preferred);
	}

	/** Attribute key to use on Bid model for insert/update (uppercase). */
	public static function liveAttributeKeyFor(string $appKey): string
	{
		$resolved = self::resolveColumnName($appKey);

		return $resolved !== null ? strtoupper($resolved) : strtoupper($appKey);
	}

	private static function matchesLiveColumn(string $appKey): bool
	{
		$map = self::columnMap();
		if ($map === null) {
			return true;
		}

		foreach (self::liveColumnCandidates($appKey) as $lower) {
			if (isset($map[$lower])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string> lowercase live column names that satisfy an app attribute key
	 */
	private static function liveColumnCandidates(string $appKey): array
	{
		$upper = strtoupper($appKey);
		$names = self::APP_COLUMN_ALIASES[$upper] ?? [$upper];

		return array_map(static fn (string $name): string => strtolower($name), $names);
	}

	/**
	 * @return array<string, string>|null null = schema unreadable (caller may pass attrs through)
	 */
	private static function columnMap(): ?array
	{
		self::resolveSchema();

		return self::$columnMapLower;
	}

	private static function resolveSchema(): void
	{
		if (self::$schemaResolved) {
			return;
		}
		self::$schemaResolved = true;

		$listing = [];
		try {
			$listing = Schema::getColumnListing((new Bid())->getTable());
		} catch (\Throwable $e) {
			Log::warning('BidLiveColumnFilter: could not read bid table columns', ['error' => $e->getMessage()]);
		}

		$listing = array_values(array_unique(array_merge(
			$listing,
			self::CANONICAL_ORACLE_BID_COLUMNS,
		)));

		if ($listing === []) {
			self::$allowedLower = null;
			self::$columnMapLower = null;

			return;
		}

		$allowed = [];
		$map = [];
		foreach ($listing as $col) {
			$lower = strtolower((string) $col);
			$allowed[$lower] = true;
			$map[$lower] = (string) $col;
		}
		self::$allowedLower = $allowed;
		self::$columnMapLower = $map;
	}
}
