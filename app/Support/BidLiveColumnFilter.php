<?php

namespace App\Support;

use App\Models\Bid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drop attribute keys that are not real columns on the live bid table (Oracle vs MySQL).
 * Preserves the caller's attribute key casing so Eloquent fill() matches $fillable.
 */
final class BidLiveColumnFilter
{
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
			$lower = strtolower((string) $key);
			if (isset($map[$lower])) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	public static function resolveColumnName(string $preferred): ?string
	{
		$map = self::columnMap();
		if ($map === null) {
			return $preferred;
		}

		return $map[strtolower($preferred)] ?? null;
	}

	public static function hasColumn(string $preferred): bool
	{
		$map = self::columnMap();
		if ($map === null) {
			return true;
		}

		return isset($map[strtolower($preferred)]);
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

		try {
			$listing = Schema::getColumnListing((new Bid())->getTable());
		} catch (\Throwable $e) {
			Log::warning('BidLiveColumnFilter: could not read bid table columns', ['error' => $e->getMessage()]);
			self::$allowedLower = null;
			self::$columnMapLower = null;

			return;
		}

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
