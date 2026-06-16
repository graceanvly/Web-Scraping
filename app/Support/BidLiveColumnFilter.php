<?php

namespace App\Support;

use App\Models\Bid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drop attribute keys that are not real columns on the live bid table (Oracle vs MySQL).
 */
final class BidLiveColumnFilter
{
	/** @var array<string, true>|null|null when schema unreadable */
	private static ?array $allowedLower = null;

	/** @var bool */
	private static bool $schemaResolved = false;

	public static function filter(array $attributes): array
	{
		$allowed = self::allowedLowerKeys();
		if ($allowed === null) {
			return $attributes;
		}
		$out = [];
		foreach ($attributes as $key => $value) {
			if (isset($allowed[strtolower((string) $key)])) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, true>|null null = allow all (schema unreadable)
	 */
	private static function allowedLowerKeys(): ?array
	{
		if (self::$schemaResolved) {
			return self::$allowedLower;
		}
		self::$schemaResolved = true;
		try {
			$listing = Schema::getColumnListing((new Bid())->getTable());
		} catch (\Throwable $e) {
			Log::warning('BidLiveColumnFilter: could not read bid table columns', ['error' => $e->getMessage()]);
			self::$allowedLower = null;

			return null;
		}
		if ($listing === []) {
			self::$allowedLower = null;

			return null;
		}
		$allowed = [];
		foreach ($listing as $col) {
			$allowed[strtolower((string) $col)] = true;
		}
		self::$allowedLower = $allowed;

		return self::$allowedLower;
	}
}
