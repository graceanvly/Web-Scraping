<?php

namespace App\Support;

/**
 * Ports WebObjects BidsDirectAction.encodeBidID and WebUtils.normalizeUrlSearchTerm
 * so listing links match production URLs under stateandfederalbids.com.
 *
 * The live site derives the numeric bid PK from DirectAction.decodeIDFromURL (substring
 * after the last hyphen). That PK must match this app's Bid primary key ID when the scrape
 * DB shares identifiers with master ODS; otherwise links may 404 or show the wrong bid.
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
	 * Full ShowBid URL for a bid row, or null if no primary key.
	 */
	public static function urlForBid(?string $title, int|string|null $bidId): ?string
	{
		$slug = self::encodeBidSlug($title, $bidId);

		return $slug !== null ? self::showBidUrl($slug) : null;
	}
}
