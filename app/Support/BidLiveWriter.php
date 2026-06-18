<?php

namespace App\Support;

use App\Models\Bid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Apply pending-bid attributes onto a live Bid row (Oracle-safe). */
final class BidLiveWriter
{
	/** @var list<string> */
	private const REFERENCE_ID_COLUMNS = [
		'ENTITYID',
		'STATEID',
		'BID_URL_ID',
		'CATEGORYID',
		'USERID',
	];

	/**
	 * @param  array<string, mixed>  $attrs
	 */
	public static function applyAttributes(Bid $bid, array $attrs): void
	{
		foreach (BidLiveColumnFilter::normalizeAttributeKeys($attrs) as $key => $value) {
			if (strcasecmp((string) $key, 'ID') === 0) {
				continue;
			}
			$bid->setAttribute((string) $key, $value);
		}
	}

	/**
	 * Force reference IDs onto the live row even when Eloquent insert/update omits them.
	 *
	 * @param  array<string, mixed>  $attrs  logical uppercase keys (ENTITYID, …)
	 */
	public static function patchReferenceIds(Bid $bid, array $attrs): void
	{
		$pk = $bid->getKey();
		if ($pk === null || $pk === '') {
			return;
		}

		$patch = [];
		foreach (self::REFERENCE_ID_COLUMNS as $logical) {
			$value = self::attrValue($attrs, $logical);
			if ($value === null || $value === '') {
				continue;
			}
			$column = BidLiveColumnFilter::resolveColumnName($logical) ?? strtoupper($logical);
			$patch[$column] = (int) $value;
		}

		if ($patch === []) {
			PendingBidApproveLogger::referencePatch($bid, [], 'skipped_empty');

			return;
		}

		PendingBidApproveLogger::referencePatch($bid, $patch, 'before');

		$table = $bid->getTable();
		$pkColumn = BidLiveColumnFilter::resolveColumnName('ID') ?? 'ID';

		try {
			DB::table($table)->where($pkColumn, $pk)->update($patch);
		} catch (\Throwable $e) {
			Log::error('BidLiveWriter: failed to patch live reference IDs', [
				'live_id' => $pk,
				'patch' => $patch,
				'error' => $e->getMessage(),
			]);
			throw $e;
		}

		foreach ($patch as $column => $value) {
			$bid->setAttribute($column, $value);
		}

		PendingBidApproveLogger::referencePatch($bid, $patch, 'after');
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 */
	private static function attrValue(array $attrs, string $logical): mixed
	{
		if (array_key_exists($logical, $attrs)) {
			return $attrs[$logical];
		}

		$lower = strtolower($logical);
		if (array_key_exists($lower, $attrs)) {
			return $attrs[$lower];
		}

		return null;
	}
}
