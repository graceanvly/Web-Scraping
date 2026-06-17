<?php

namespace App\Support;

use App\Models\TempBid;
use Illuminate\Support\Facades\Log;

/** Map a pending (bids_temp) row onto attributes for the live BID table. */
final class PendingBidLiveMapper
{
	/** @var list<string> */
	private const INTEGER_COLUMNS = [
		'CATEGORYID',
		'ENTITYID',
		'SUBSCRIPTIONTYPEID',
		'USERID',
		'SETASIDECODEID',
		'BID_URL_ID',
		'INLINEURL',
		'NEEDS_REVIEW',
		'SOURCE_ID',
		'STATEID',
		'CATEGORY_ALIAS_ID',
		'UNDERREVIEW',
		'NAICSCODE_INT',
	];

	/**
	 * @return array<string, mixed>
	 */
	public static function attributesForInsert(TempBid $pendingBid): array
	{
		$attrs = BidLiveColumnFilter::filter($pendingBid->toLiveBidAttributes());

		foreach (TempBid::BID_COLUMNS as $logical) {
			if (in_array($logical, ['raw_html', 'extracted_json'], true)) {
				continue;
			}
			$attrs = self::mergeColumn($attrs, $pendingBid, $logical);
		}

		$title = trim((string) ($pendingBid->getAttribute('TITLE') ?? ''));
		if ($title === '') {
			throw new \RuntimeException('Pending bid title is required before promoting to live.');
		}
		$attrs['TITLE'] = mb_substr($title, 0, 255);
		$attrs['LAST_MODIFIED'] = now();

		return self::normalizeFillableKeys($attrs);
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 * @return array<string, mixed>
	 */
	private static function mergeColumn(array $attrs, TempBid $pendingBid, string $logical): array
	{
		if (!BidLiveColumnFilter::hasColumn($logical)) {
			if ($logical === 'TITLE') {
				Log::warning('Pending promote: TITLE missing from live BID schema listing', [
					'temp_id' => $pendingBid->id,
				]);
			}

			return $attrs;
		}

		$val = $pendingBid->getAttribute($logical);
		if ($val === null || $val === '') {
			return $attrs;
		}

		$attrs[$logical] = self::castValue($logical, $val);

		return $attrs;
	}

	private static function castValue(string $logical, mixed $val): mixed
	{
		if (in_array($logical, self::INTEGER_COLUMNS, true) && is_numeric($val)) {
			return (int) $val;
		}

		return $val;
	}

	/**
	 * Uppercase keys so they match Bid::$fillable (Eloquent fill is case-sensitive).
	 *
	 * @param  array<string, mixed>  $attrs
	 * @return array<string, mixed>
	 */
	private static function normalizeFillableKeys(array $attrs): array
	{
		$out = [];
		foreach ($attrs as $key => $value) {
			$out[strtoupper((string) $key)] = $value;
		}

		return $out;
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
