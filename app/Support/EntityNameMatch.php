<?php

namespace App\Support;

/**
 * Normalization helpers for matching scraped bids to master entity rows.
 */
final class EntityNameMatch
{
	/** @var list<string> Host suffixes that are procurement portals, not buying agencies. */
	private const AGGREGATOR_HOST_SUFFIXES = [
		'bonfirehub.com',
		'planetbids.com',
		'ionwave.net',
		'publicpurchase.com',
		'vendorlink.com',
		'bidnetdirect.com',
		'demandstar.com',
		'public-portal.us.workdayspend.com',
	];

	/**
	 * Hostnames suitable for entity website/email-domain matching (excludes portal vendors).
	 *
	 * @return list<string>
	 */
	public static function meaningfulUrlHosts(string ...$urls): array
	{
		$hosts = [];
		foreach ($urls as $url) {
			$host = self::normalizeUrlHost($url);
			if ($host === '' || self::isPortalHost($host)) {
				continue;
			}
			$hosts[$host] = true;
		}

		return array_keys($hosts);
	}

	public static function isPortalHost(string $host): bool
	{
		$host = self::normalizeUrlHost($host);
		if ($host === '') {
			return false;
		}

		if (ThirdPartyProcurementPortalUrl::referencesPortal('https://' . $host)) {
			return true;
		}

		foreach (self::AGGREGATOR_HOST_SUFFIXES as $suffix) {
			if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
				return true;
			}
		}

		return false;
	}

	public static function normalizeUrlHost(string $url): string
	{
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*:/#i', $url)) {
			$url = 'https://' . $url;
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!$host || !is_string($host)) {
			return '';
		}

		return preg_replace('/^www\./', '', strtolower($host));
	}

	/**
	 * Canonical comparison key for entity / organization names.
	 */
	public static function canonicalKey(string $name): string
	{
		$n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($name))));
		$n = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $n) ?? $n;
		$n = trim(preg_replace('/\s+/u', ' ', $n));

		if ($n === '') {
			return '';
		}

		if (preg_match('/^city and county of (.+)$/u', $n, $m)) {
			return trim($m[1]) . ' city county';
		}
		if (preg_match('/^(.+),?\s+city of$/u', $n, $m)) {
			return trim($m[1]) . ' city';
		}
		if (preg_match('/^city of (.+)$/u', $n, $m)) {
			return trim($m[1]) . ' city';
		}
		if (preg_match('/^county of (.+)$/u', $n, $m)) {
			return trim($m[1]) . ' county';
		}
		if (preg_match('/^(.+)\s+county$/u', $n, $m)) {
			return trim($m[1]) . ' county';
		}
		if (preg_match('/^(.+)\s+city$/u', $n, $m)) {
			return trim($m[1]) . ' city';
		}

		return $n;
	}

	/**
	 * Remove portal vendor labels mistakenly returned as ISSUING_ORGANIZATION.
	 */
	public static function stripPortalVendorNames(string $org): string
	{
		$s = trim(preg_replace('/\s+/u', ' ', strip_tags($org)));
		if ($s === '') {
			return '';
		}

		$patterns = [
			'/\bbonfire\s*hub\b/iu',
			'/\bbidnet\s*direct\b/iu',
			'/\bdemandstar\b/iu',
			'/\bplanet\s*bids\b/iu',
			'/\bionwave\b/iu',
			'/\bpublic\s*purchase\b/iu',
			'/\bvendorlink\b/iu',
			'/\bworkday\s*spend\b/iu',
		];
		foreach ($patterns as $pattern) {
			$s = trim(preg_replace($pattern, '', $s));
		}

		return trim($s, " \t\n\r\0\x0B,-·|—–");
	}

	/**
	 * Significant tokens for overlap scoring (length ≥ 3, not stopwords).
	 *
	 * @return list<string>
	 */
	public static function significantTokens(string $text): array
	{
		$key = self::canonicalKey($text);
		if ($key === '') {
			return [];
		}

		$stop = ['the', 'and', 'for', 'of', 'city', 'county', 'state', 'department', 'dept', 'school', 'district', 'public', 'services'];
		$tokens = preg_split('/\s+/u', $key) ?: [];
		$out = [];
		foreach ($tokens as $token) {
			$token = trim($token);
			if (mb_strlen($token) < 3 || in_array($token, $stop, true)) {
				continue;
			}
			$out[$token] = true;
		}

		return array_keys($out);
	}

	public static function tokenOverlapScore(string $hint, string $entityName): float
	{
		return self::tokenOverlapTokens(self::significantTokens($hint), self::significantTokens($entityName));
	}

	/**
	 * Overlap score from already-tokenized inputs (avoids re-running regex per comparison).
	 *
	 * @param list<string> $hintTokens
	 * @param list<string> $nameTokens
	 */
	public static function tokenOverlapTokens(array $hintTokens, array $nameTokens): float
	{
		if ($hintTokens === [] || $nameTokens === []) {
			return 0.0;
		}

		$shared = array_intersect($hintTokens, $nameTokens);
		if ($shared === []) {
			return 0.0;
		}

		$denom = max(count($hintTokens), count($nameTokens));

		return min(92.0, 65.0 + (count($shared) / $denom) * 35.0);
	}

	/**
	 * Weak org hint from agency subdomain on a portal host (e.g. foo-bar.bonfirehub.com).
	 */
	public static function organizationHintFromSubdomain(string $url): string
	{
		$host = self::normalizeUrlHost($url);
		if ($host === '' || !self::isPortalHost($host)) {
			return '';
		}

		$labels = explode('.', $host);
		if (count($labels) < 3) {
			return '';
		}

		$sub = $labels[0];
		if (mb_strlen($sub) < 4) {
			return '';
		}

		if (preg_match('/[-_]/', $sub)) {
			$words = preg_split('/[-_]+/', $sub) ?: [];
			$words = array_values(array_filter(array_map('trim', $words), fn ($w) => mb_strlen($w) >= 2));

			return $words === [] ? '' : mb_convert_case(implode(' ', $words), MB_CASE_TITLE, 'UTF-8');
		}

		return '';
	}
}
