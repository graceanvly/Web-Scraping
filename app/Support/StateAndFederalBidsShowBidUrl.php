<?php

namespace App\Support;

/**
 * Ports WebObjects BidsDirectAction.encodeBidID and WebUtils.normalizeUrlSearchTerm
 * so listing links match production URLs under stateandfederalbids.com.
 *
 * The live site derives the numeric bid PK from DirectAction.decodeIDFromURL (substring
 * after the last hyphen). Prefer Bid.THIRD_PARTY_IDENTIFIER when it is numeric (production ODS id).
 * Scraper Bid.ID comes from BID_SEQ and differs from SAFB prod unless
 * SCRAPER_STATEANDFEDERALBIDS_SHOWBID_TRUST_LOCAL_BID_ID=true on a shared-write Oracle.
 */
final class StateAndFederalBidsShowBidUrl
{
	/**
	 * Mirrors WebUtils.normalizeUrlSearchTerm: non letter/digit becomes '-'.
	 * Uses Unicode letter/number categories (closest to Java Character.isLetterOrDigit).
	 */
	public static function normalizeUrlSearchTerm(string $value): string
	{
		$buffer = '';
		foreach (mb_str_split($value) as $c) {
			if (preg_match('/^\p{L}$/u', $c) !== 0 || preg_match('/^\p{N}$/u', $c) !== 0) {
				$buffer .= $c;
			} else {
				$buffer .= '-';
			}
		}

		return $buffer;
	}

	public static function encodeUnknownBidSlug(string $primaryKey): string
	{
		return 'Unknown-' . $primaryKey;
	}

	/**
	 * Mirrors BidsDirectAction.encodeBidID: first 40 UTF-8 chars of title, normalized,
	 * then '-' and primary key; on failure Unknown-{pk}.
	 */
	public static function encodeBidSlug(?string $title, int|string|null $bidId): ?string
	{
		if ($bidId === null || $bidId === '') {
			return null;
		}

		$id = (string) $bidId;

		try {
			$t = $title ?? '';
			if ($t === '') {
				return self::encodeUnknownBidSlug($id);
			}

			$truncated = mb_substr($t, 0, 40, 'UTF-8');
			if ($truncated === '') {
				return self::encodeUnknownBidSlug($id);
			}

			return self::normalizeUrlSearchTerm($truncated) . '-' . $id;
		} catch (\Throwable) {
			return self::encodeUnknownBidSlug($id);
		}
	}

	public static function showBidUrl(string $slug): string
	{
		$base = rtrim((string) config('scraper.stateandfederalbids_showbid_base_url', 'https://www.stateandfederalbids.com/bids/ShowBid/'), '/') . '/';

		return $base . ltrim($slug, '/');
	}

	/**
	 * ODS PK for ShowBid slug suffix: numeric THIRD_PARTY_IDENTIFIER, else Bid.ID only if trusted.
	 */
	public static function resolveShowBidPk(int|string|null $bidId, ?string $thirdPartyIdentifier = null): ?string
	{
		$tp = trim((string) ($thirdPartyIdentifier ?? ''));
		if ($tp !== '' && preg_match('/^\d+$/', $tp) === 1) {
			return $tp;
		}

		$trustLocal = filter_var(config('scraper.stateandfederalbids_showbid_trust_local_bid_id', false), FILTER_VALIDATE_BOOL);
		if (!$trustLocal) {
			return null;
		}

		if ($bidId === null || $bidId === '') {
			return null;
		}

		return preg_match('/^\d+$/', (string) $bidId) === 1 ? (string) $bidId : null;
	}

	/**
	 * Full ShowBid URL for a bid row, or null when no usable ODS pk (see resolveShowBidPk).
	 *
	 * @param int|string|null $bidId        Local bid primary key (BID_SEQ unless shared Oracle).
	 * @param ?string          $thirdPartyId Bid.THIRD_PARTY_IDENTIFIER — production numeric id when set.
	 */
	public static function urlForBid(?string $title, int|string|null $bidId, ?string $thirdPartyId = null): ?string
	{
		$pk = self::resolveShowBidPk($bidId, $thirdPartyId);
		if ($pk === null) {
			return null;
		}

		$slug = self::encodeBidSlug($title, $pk);

		return $slug !== null ? self::showBidUrl($slug) : null;
	}
}
