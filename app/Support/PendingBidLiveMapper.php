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
		$titleCol = BidLiveColumnFilter::resolveColumnName('TITLE') ?? 'TITLE';
		$attrs[$titleCol] = mb_substr($title, 0, 255);

		$lastModCol = BidLiveColumnFilter::resolveColumnName('LAST_MODIFIED') ?? 'LAST_MODIFIED';
		$attrs[$lastModCol] = now();

		return $attrs;
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 * @return array<string, mixed>
	 */
	private static function mergeColumn(array $attrs, TempBid $pendingBid, string $logical): array
	{
		$val = $pendingBid->getAttribute($logical);
		if ($val === null || $val === '') {
			return $attrs;
		}

		$col = BidLiveColumnFilter::resolveColumnName($logical);
		if ($col === null) {
			if ($logical === 'TITLE') {
				Log::warning('Pending promote: TITLE missing from live BID schema listing', [
					'temp_id' => $pendingBid->id,
				]);
			}

			return $attrs;
		}

		$attrs[$col] = self::castValue($logical, $val);

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
