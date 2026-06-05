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
	 *
	 * @deprecated Prefer resolveSavedBidUrl() which falls back to agency listing URLs and scraped detail pages.
	 */
	public static function savedBidUrl(string $sourceUrl, string $detailUrl): string
	{
		return self::resolveSavedBidUrl($sourceUrl, $detailUrl);
	}

	/**
	 * Pick the best non-portal URL to store on bid.URL.
	 *
	 * Priority: AI/detail URL → matched scraped bid page → agency listing URL → empty (portal-only scrape).
	 */
	public static function resolveSavedBidUrl(
		string $sourceUrl,
		string $detailUrl,
		array $bidPages = [],
		?string $title = null
	): string {
		$sourceUrl = trim($sourceUrl);
		$detailUrl = trim($detailUrl);

		if ($sourceUrl !== '' && self::referencesPortal($sourceUrl)) {
			return '';
		}

		if ($detailUrl !== '' && !self::referencesPortal($detailUrl)) {
			return $detailUrl;
		}

		$matchedPageUrl = self::matchBidPageUrl($bidPages, $title);
		if ($matchedPageUrl !== null && $matchedPageUrl !== '') {
			return $matchedPageUrl;
		}

		if ($sourceUrl !== '' && !self::referencesPortal($sourceUrl)) {
			return $sourceUrl;
		}

		return '';
	}

	/**
	 * @param array<int, array{url?: string, title?: string}> $bidPages
	 */
	private static function matchBidPageUrl(array $bidPages, ?string $title): ?string
	{
		$titleNorm = self::normalizeTitleForMatch((string) $title);
		if ($titleNorm === '') {
			return null;
		}

		foreach ($bidPages as $page) {
			$pageUrl = trim((string) ($page['url'] ?? ''));
			if ($pageUrl === '' || self::referencesPortal($pageUrl)) {
				continue;
			}

			$pageTitleNorm = self::normalizeTitleForMatch((string) ($page['title'] ?? ''));
			if ($pageTitleNorm === '') {
				continue;
			}

			if (self::titlesLikelySameBid($titleNorm, $pageTitleNorm)) {
				return $pageUrl;
			}
		}

		return null;
	}

	private static function normalizeTitleForMatch(string $title): string
	{
		$title = strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
		$title = preg_replace('/[^a-z0-9\s]/', '', $title) ?? '';

		return trim($title);
	}

	private static function titlesLikelySameBid(string $a, string $b): bool
	{
		if ($a === '' || $b === '') {
			return false;
		}

		if (str_contains($a, $b) || str_contains($b, $a)) {
			return true;
		}

		similar_text($a, $b, $percent);

		return $percent >= 55.0;
	}
}
