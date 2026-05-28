<?php

namespace App\Support;

/**
 * Third-party procurement portals whose detail URLs must not be stored on bid rows.
 */
final class ThirdPartyProcurementPortalUrl
{
	/** @var list<string> Substrings matched case-insensitively anywhere in a URL. */
	private const PORTAL_MARKERS = [
		'demandstar',
		'vendorlink',
		'bidnetdirect',
		'publicpurchase',
	];

	public static function referencesPortal(?string $url): bool
	{
		$url = strtolower(trim((string) $url));
		if ($url === '') {
			return false;
		}

		foreach (self::PORTAL_MARKERS as $marker) {
			if (str_contains($url, $marker)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * URL to persist on bid.URL: empty when source or detail references a restricted portal.
	 */
	public static function savedBidUrl(string $sourceUrl, string $detailUrl): string
	{
		if (self::referencesPortal($sourceUrl) || self::referencesPortal($detailUrl)) {
			return '';
		}

		return $detailUrl;
	}
}
