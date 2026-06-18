<?php

namespace App\Support;

use App\Models\Bid;
use App\Models\TempBid;
use Illuminate\Http\Request;

/** Structured diagnostics for pending bid approve / promote. */
final class PendingBidApproveLogger
{
	private const ENABLED = false;

	public static function requestReceived(Request $request, TempBid $pendingBid): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('request_received', [
			'temp_id' => $pendingBid->id,
			'route' => $request->route()?->getName(),
			'method' => $request->method(),
			'edit_modal' => $request->input('edit_modal'),
			'approve_action' => $request->input('approve_action'),
			'has_title' => $request->has('TITLE'),
			'request_entityid' => $request->input('ENTITYID'),
			'request_stateid' => $request->input('STATEID'),
			'request_bid_url_id' => $request->input('BID_URL_ID'),
			'request_categoryid' => $request->input('CATEGORYID'),
			'request_userid' => $request->input('USERID'),
			'request_has_entityid' => $request->has('ENTITYID'),
			'request_has_stateid' => $request->has('STATEID'),
			'request_has_bid_url_id' => $request->has('BID_URL_ID'),
		]);
	}

	public static function tempRowPrepared(TempBid $pendingBid, array $referenceIds): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('temp_row_before_promote', [
			'temp_id' => $pendingBid->id,
			'title' => $pendingBid->getAttribute('TITLE'),
			'temp_entityid' => $pendingBid->getAttribute('ENTITYID'),
			'temp_stateid' => $pendingBid->getAttribute('STATEID'),
			'temp_bid_url_id' => $pendingBid->getAttribute('BID_URL_ID'),
			'temp_categoryid' => $pendingBid->getAttribute('CATEGORYID'),
			'extracted_reference_ids' => $referenceIds,
		]);
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 */
	public static function promoteAttrsBuilt(int $tempId, array $referenceIds, array $attrs): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('promote_attrs_built', [
			'temp_id' => $tempId,
			'request_entityid' => $referenceIds['ENTITYID'] ?? null,
			'request_stateid' => $referenceIds['STATEID'] ?? null,
			'request_bid_url_id' => $referenceIds['BID_URL_ID'] ?? null,
			'attr_entityid' => $attrs['ENTITYID'] ?? null,
			'attr_stateid' => $attrs['STATEID'] ?? null,
			'attr_bid_url_id' => $attrs['BID_URL_ID'] ?? null,
			'attr_categoryid' => $attrs['CATEGORYID'] ?? null,
			'attr_keys' => array_keys($attrs),
		]);
	}

	public static function bidModelBeforeSave(Bid $bid, string $context): void
	{
		if (!self::ENABLED) {
			return;
		}

		$attrs = $bid->getAttributes();
		self::info('bid_model_before_save', [
			'context' => $context,
			'live_id' => $bid->getKey(),
			'entityid' => self::readAttr($attrs, 'ENTITYID'),
			'stateid' => self::readAttr($attrs, 'STATEID'),
			'bid_url_id' => self::readAttr($attrs, 'BID_URL_ID'),
			'categoryid' => self::readAttr($attrs, 'CATEGORYID'),
			'title' => self::readAttr($attrs, 'TITLE'),
			'attribute_keys' => array_keys($attrs),
		]);
	}

	/**
	 * @param  array<string, mixed>  $patch
	 */
	public static function referencePatch(Bid $bid, array $patch, string $phase): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('reference_patch_' . $phase, [
			'live_id' => $bid->getKey(),
			'patch' => $patch,
		]);
	}

	public static function duplicateMatched(int $tempId, int|string $liveId, bool $willUpdate): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('duplicate_live_bid_matched', [
			'temp_id' => $tempId,
			'live_id' => $liveId,
			'will_update_live' => $willUpdate,
		]);
	}

	public static function duplicateSkipped(int $tempId, int|string $liveId): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::warning('duplicate_live_bid_skipped_update', [
			'temp_id' => $tempId,
			'live_id' => $liveId,
			'hint' => 'Matched an existing live bid but did not apply edit-modal fields.',
		]);
	}

	public static function verifyLiveRow(Bid $bid, string $context): void
	{
		if (!self::ENABLED) {
			return;
		}

		$pk = $bid->getKey();
		if ($pk === null || $pk === '') {
			self::warning('verify_live_row_no_pk', ['context' => $context]);

			return;
		}

		$fresh = Bid::query()->find($pk);
		if ($fresh === null) {
			self::warning('verify_live_row_not_found', ['context' => $context, 'live_id' => $pk]);

			return;
		}

		self::info('verify_live_row_after_save', [
			'context' => $context,
			'live_id' => $pk,
			'entityid' => $fresh->getAttribute('ENTITYID'),
			'stateid' => $fresh->getAttribute('STATEID'),
			'bid_url_id' => $fresh->getAttribute('BID_URL_ID'),
			'categoryid' => $fresh->getAttribute('CATEGORYID'),
		]);
	}

	public static function updateReceived(Request $request, TempBid $pendingBid): void
	{
		if (!self::ENABLED) {
			return;
		}

		self::info('update_request_received', [
			'temp_id' => $pendingBid->id,
			'approve_action' => $request->input('approve_action'),
			'request_entityid' => $request->input('ENTITYID'),
			'request_stateid' => $request->input('STATEID'),
			'request_bid_url_id' => $request->input('BID_URL_ID'),
		]);
	}

	/**
	 * @param  array<string, mixed>  $attrs
	 */
	private static function readAttr(array $attrs, string $logical): mixed
	{
		foreach ($attrs as $key => $value) {
			if (strcasecmp((string) $key, $logical) === 0) {
				return $value;
			}
		}

		return null;
	}

	private static function info(string $stage, array $context): void
	{
		// Log::info('Pending approve: ' . $stage, $context);
	}

	private static function warning(string $stage, array $context): void
	{
		// Log::warning('Pending approve: ' . $stage, $context);
	}
}
