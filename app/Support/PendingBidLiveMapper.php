<?php

namespace App\Support;

use App\Models\TempBid;
use Illuminate\Support\Facades\Log;

/** Map a pending (bids_temp) row onto attributes for the live BID table. */
final class PendingBidLiveMapper
{
	/** @var list<string> */
	private const REFERENCE_COLUMNS = [
		'ENTITYID',
		'STATEID',
		'BID_URL_ID',
		'CATEGORYID',
		'USERID',
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function attributesForInsert(TempBid $pendingBid): array
	{
		$attrs = BidLiveColumnFilter::filter($pendingBid->toLiveBidAttributes());

		foreach (self::REFERENCE_COLUMNS as $logical) {
			$val = $pendingBid->getAttribute($logical);
			if ($val === null || $val === '') {
				continue;
			}
			$col = BidLiveColumnFilter::resolveColumnName($logical);
			if ($col === null) {
				Log::warning('Pending promote: live BID table has no column', [
					'column' => $logical,
					'temp_id' => $pendingBid->id,
				]);

				continue;
			}
			$attrs[$col] = (int) $val;
		}

		$lastModCol = BidLiveColumnFilter::resolveColumnName('LAST_MODIFIED') ?? 'LAST_MODIFIED';
		$attrs[$lastModCol] = now();

		return $attrs;
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 * @return array<string, mixed>
	 */
	public static function withoutPrimaryKey(array $attrs): array
	{
		foreach (array_keys($attrs) as $key) {
			if (strcasecmp((string) $key, 'ID') === 0) {
				unset($attrs[$key]);
			}
		}

		return $attrs;
	}
}
