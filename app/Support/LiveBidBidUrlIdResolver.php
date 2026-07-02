<?php

namespace App\Support;

use App\Models\BidUrl;
use App\Support\BidUrlTableConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Two-table bid URL model:
 * - BID_URL (scraper): configured URLs for Scrape All / Manage URLs.
 * - BIDURL (ODS): production master list; live BID.BID_URL_ID must reference BIDURL.ID.
 *
 * This resolver never writes to BIDURL — it looks up BIDURL.ID by URL when promoting
 * or displaying live bids. Scraper operations keep using BID_URL.
 */
final class LiveBidBidUrlIdResolver
{
	/** @var array<string, true> */
	private static array $odsUrlIndex = [];

	private static bool $odsUrlIndexBuilt = false;

	public static function liveReferencesOdsBidUrl(): bool
	{
		return filter_var(config('scraper.live_bid_url_id_references_ods', true), FILTER_VALIDATE_BOOL);
	}

	public static function resetCache(): void
	{
		self::$odsUrlIndex = [];
		self::$odsUrlIndexBuilt = false;
	}

	/**
	 * Resolve ODS BIDURL.ID from a scraper BID_URL row (third_party_url_id, then URL match).
	 */
	public static function resolveFromScraperBidUrl(?BidUrl $bidUrl, ?string $listingUrl = null): ?int
	{
		if ($bidUrl === null) {
			return self::resolveOdsIdFromUrl((string) ($listingUrl ?? ''));
		}

		$thirdPartyId = self::readThirdPartyUrlId($bidUrl);
		if ($thirdPartyId > 0 && self::liveReferencesOdsBidUrl() && self::odsTableAvailable() && self::odsIdExists($thirdPartyId)) {
			return $thirdPartyId;
		}

		$url = trim((string) ($listingUrl ?? ''));
		if ($url === '') {
			$url = trim((string) ($bidUrl->getAttribute('url') ?? $bidUrl->getAttribute('URL') ?? ''));
		}

		$fromUrl = self::resolveOdsIdFromUrl($url);
		if ($fromUrl !== null && $fromUrl > 0) {
			return $fromUrl;
		}

		return self::resolveForLiveWrite((int) $bidUrl->getKey(), $url !== '' ? $url : null);
	}

	/**
	 * Resolve the ID that belongs on live BID.BID_URL_ID (ODS BIDURL.ID in production).
	 */
	public static function resolveForLiveWrite(?int $bidUrlId, ?string $urlHint = null): ?int
	{
		if ($bidUrlId === null || $bidUrlId < 1) {
			return self::resolveOdsIdFromUrl((string) ($urlHint ?? ''));
		}

		if (!self::liveReferencesOdsBidUrl() || !self::odsTableAvailable()) {
			return $bidUrlId;
		}

		if (self::odsIdExists($bidUrlId)) {
			return $bidUrlId;
		}

		$mapped = self::resolveOdsIdFromScraperId($bidUrlId);
		if ($mapped !== null && $mapped > 0) {
			return $mapped;
		}

		$urlMapped = self::resolveOdsIdFromUrl((string) ($urlHint ?? ''));
		if ($urlMapped !== null && $urlMapped > 0) {
			return $urlMapped;
		}

		Log::warning('LiveBidBidUrlIdResolver: could not map scraper bid URL id to ODS BIDURL', [
			'scraper_bid_url_id' => $bidUrlId,
			'url_hint' => $urlHint,
		]);

		return $bidUrlId;
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	public static function lookupOptionById(int $id): ?array
	{
		if ($id < 1) {
			return null;
		}

		if (self::odsTableAvailable()) {
			$ods = self::lookupOdsRowById($id);
			if ($ods !== null) {
				return $ods;
			}
		}

		return null;
	}

	public static function resolveOdsIdFromScraperId(int $scraperId): ?int
	{
		if ($scraperId < 1) {
			return null;
		}

		$url = self::scraperUrlForId($scraperId);
		if ($url === null || $url === '') {
			return null;
		}

		return self::resolveOdsIdFromUrl($url);
	}

	public static function resolveOdsIdFromUrl(string $url): ?int
	{
		$normalized = self::normalizeUrl($url);
		if ($normalized === '' || !self::odsTableAvailable()) {
			return null;
		}

		self::ensureOdsUrlIndex();

		return self::$odsUrlIndex[$normalized] ?? null;
	}

	public static function normalizeUrl(string $url): string
	{
		$url = strtolower(trim($url));

		return rtrim($url, '/');
	}

	private static function odsTableAvailable(): bool
	{
		$table = (string) config('scraper.ods_bidurl_table', 'BIDURL');

		try {
			return Schema::hasTable($table);
		} catch (\Throwable) {
			return false;
		}
	}

	private static function odsIdExists(int $id): bool
	{
		$table = (string) config('scraper.ods_bidurl_table', 'BIDURL');
		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');

		try {
			return DB::table($table)->where($idCol, $id)->exists();
		} catch (\Throwable) {
			return false;
		}
	}

	private static function scraperUrlForId(int $scraperId): ?string
	{
		$table = BidUrlTableConfig::table();
		$idCol = (string) config('scraper.bid_url_id_column', 'id');
		$urlCol = (string) config('scraper.bid_url_url_column', 'url');

		try {
			$row = DB::table($table)
				->select([$idCol, $urlCol])
				->where($idCol, $scraperId)
				->first();
		} catch (\Throwable) {
			return null;
		}

		if ($row === null) {
			return null;
		}

		return trim((string) (self::rowAttr($row, $urlCol) ?? ''));
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	private static function lookupOdsRowById(int $id): ?array
	{
		$table = (string) config('scraper.ods_bidurl_table', 'BIDURL');
		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');
		$urlCol = (string) config('scraper.ods_bidurl_url_column', 'URL');
		$nameCol = (string) config('scraper.ods_bidurl_name_column', 'NAME');

		try {
			$row = DB::table($table)
				->select([$idCol, $urlCol, $nameCol])
				->where($idCol, $id)
				->first();
		} catch (\Throwable) {
			return null;
		}

		if ($row === null) {
			return null;
		}

		$url = trim((string) (self::rowAttr($row, $urlCol) ?? ''));
		if ($url === '') {
			return null;
		}

		$name = trim((string) (self::rowAttr($row, $nameCol) ?? ''));
		$idRaw = self::rowAttr($row, $idCol);

		return [
			'id' => (int) $idRaw,
			'label' => $name !== '' ? ($name . ' — ' . $url) : $url,
			'url' => $url,
		];
	}

	private static function ensureOdsUrlIndex(): void
	{
		if (self::$odsUrlIndexBuilt) {
			return;
		}

		self::$odsUrlIndexBuilt = true;
		self::$odsUrlIndex = [];

		$table = (string) config('scraper.ods_bidurl_table', 'BIDURL');
		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');
		$urlCol = (string) config('scraper.ods_bidurl_url_column', 'URL');

		try {
			DB::table($table)
				->select([$idCol, $urlCol])
				->whereNotNull($urlCol)
				->orderBy($idCol)
				->chunk(500, function ($rows) use ($idCol, $urlCol) {
					foreach ($rows as $row) {
						$url = self::normalizeUrl((string) (self::rowAttr($row, $urlCol) ?? ''));
						$idRaw = self::rowAttr($row, $idCol);
						if ($url === '' || $idRaw === null || $idRaw === '') {
							continue;
						}
						if (!isset(self::$odsUrlIndex[$url])) {
							self::$odsUrlIndex[$url] = (int) $idRaw;
						}
					}
				});
		} catch (\Throwable $e) {
			Log::warning('LiveBidBidUrlIdResolver: could not index ODS BIDURL URLs', [
				'error' => $e->getMessage(),
			]);
		}
	}

	private static function rowAttr(object $row, string $column): mixed
	{
		foreach ((array) $row as $key => $value) {
			if (strcasecmp((string) $key, $column) === 0) {
				return $value;
			}
		}

		return null;
	}

	private static function readThirdPartyUrlId(BidUrl $bidUrl): int
	{
		foreach (['third_party_url_id', 'THIRD_PARTY_URL_ID'] as $key) {
			$value = $bidUrl->getAttribute($key);
			if ($value !== null && $value !== '' && is_numeric($value)) {
				return (int) $value;
			}
		}

		return 0;
	}
}
