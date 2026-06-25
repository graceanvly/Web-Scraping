<?php

namespace App\Support;

use App\Models\Bid;
use App\Models\TempBid;
use Illuminate\Support\Carbon;

/** Normalized keys used to detect duplicate bids across live and pending tables. */
final class BidIdentity
{
	public function __construct(
		public readonly string $normalizedDetailUrl,
		public readonly string $solicitationNumber,
		public readonly string $thirdPartyId,
		public readonly int $bidUrlId,
		public readonly ?string $endDateYmd,
		public readonly string $titleNormalized,
		public readonly string $rawTitleNormalized,
		public readonly string $rawSavedUrl = '',
		public readonly string $naicsCodeKey = '',
	) {
	}

	public function hasTierAKey(): bool
	{
		return $this->hasStrongUrlForTierA()
			|| $this->solicitationNumber !== ''
			|| $this->thirdPartyId !== ''
			|| $this->hasPortalProjectNaicsKey();
	}

	public function hasPortalProjectNaicsKey(): bool
	{
		return $this->naicsCodeKey !== '' && $this->bidUrlId > 0;
	}

	/** Domain-only / portal listing URLs are not stable bid identity keys. */
	public function hasStrongUrlForTierA(): bool
	{
		$url = $this->normalizedDetailUrl;
		if ($url === '') {
			return false;
		}

		return str_contains($url, '/');
	}

	/** @return list<string> */
	public function tierAFingerprintKeys(): array
	{
		$keys = [];
		if ($this->normalizedDetailUrl !== '') {
			$keys[] = 'url:' . $this->normalizedDetailUrl;
		}
		if ($this->solicitationNumber !== '') {
			$keys[] = 'sol:' . $this->solicitationNumber;
		}
		if ($this->thirdPartyId !== '') {
			$keys[] = 'tp:' . $this->thirdPartyId;
		}

		return $keys;
	}

	/** @return list<string> */
	public function tierBCFingerprintKeys(): array
	{
		$keys = [];
		if ($this->bidUrlId > 0 && $this->endDateYmd !== null) {
			foreach ([$this->titleNormalized, $this->rawTitleNormalized] as $title) {
				if ($title !== '') {
					$keys[] = 'bc:' . $this->bidUrlId . ':' . $this->endDateYmd . ':' . $title;
				}
			}
		}
		if ($this->hasPortalProjectNaicsKey()) {
			$keys[] = 'naics:' . $this->bidUrlId . ':' . $this->naicsCodeKey;
		}

		return $keys;
	}

	/**
	 * @param  array<string, mixed>  $bidData
	 */
	public static function fromScrapeExtract(
		array $bidData,
		string $savedUrl,
		?int $bidUrlId = null,
		?string $displayTitle = null,
		?string $normalizedNaics = null,
	): self {
		$rawTitle = trim((string) ($bidData['TITLE'] ?? ''));
		$display = trim((string) ($displayTitle ?? $rawTitle));
		$sol = self::normalizeSolicitation(
			$bidData['SOLICIATIONNUMBER'] ?? $bidData['SOLICITATIONNUMBER'] ?? null
		);
		$thirdParty = self::normalizeToken($bidData['THIRD_PARTY_IDENTIFIER'] ?? null);
		$endDate = self::normalizeEndDateYmd($bidData['ENDDATE'] ?? null);
		$naicsKey = self::normalizePortalProjectNaicsKey($normalizedNaics ?? $bidData['NAICSCODE'] ?? null);

		return new self(
			normalizedDetailUrl: self::normalizeUrlForMatch($savedUrl),
			solicitationNumber: $sol,
			thirdPartyId: $thirdParty,
			bidUrlId: max(0, (int) ($bidUrlId ?? 0)),
			endDateYmd: $endDate,
			titleNormalized: self::normalizeTitle($display),
			rawTitleNormalized: self::normalizeTitle($rawTitle),
			rawSavedUrl: trim($savedUrl),
			naicsCodeKey: $naicsKey,
		);
	}

	public static function fromTempBid(TempBid $bid): self
	{
		$title = trim((string) ($bid->TITLE ?? ''));
		$url = trim((string) ($bid->URL ?? ''));

		return new self(
			normalizedDetailUrl: self::normalizeUrlForMatch($url),
			solicitationNumber: self::normalizeSolicitation($bid->SOLICIATIONNUMBER ?? $bid->SOLICITATIONNUMBER ?? null),
			thirdPartyId: self::normalizeToken($bid->THIRD_PARTY_IDENTIFIER ?? null),
			bidUrlId: max(0, (int) ($bid->BID_URL_ID ?? 0)),
			endDateYmd: self::normalizeEndDateYmd($bid->ENDDATE ?? null),
			titleNormalized: self::normalizeTitle($title),
			rawTitleNormalized: self::normalizeTitle($title),
			rawSavedUrl: $url,
			naicsCodeKey: self::normalizePortalProjectNaicsKey($bid->NAICSCODE ?? null),
		);
	}

	public static function fromLiveBid(Bid $bid): self
	{
		$title = trim((string) ($bid->TITLE ?? ''));
		$url = trim((string) ($bid->URL ?? ''));

		return new self(
			normalizedDetailUrl: self::normalizeUrlForMatch($url),
			solicitationNumber: self::normalizeSolicitation($bid->SOLICIATIONNUMBER ?? $bid->SOLICITATIONNUMBER ?? null),
			thirdPartyId: self::normalizeToken($bid->THIRD_PARTY_IDENTIFIER ?? null),
			bidUrlId: max(0, (int) ($bid->BID_URL_ID ?? 0)),
			endDateYmd: self::normalizeEndDateYmd($bid->ENDDATE ?? null),
			titleNormalized: self::normalizeTitle($title),
			rawTitleNormalized: self::normalizeTitle($title),
			rawSavedUrl: $url,
			naicsCodeKey: self::normalizePortalProjectNaicsKey($bid->NAICSCODE ?? null),
		);
	}

	public function matchesTierB(self $other): bool
	{
		if ($this->bidUrlId < 1 || $other->bidUrlId < 1 || $this->bidUrlId !== $other->bidUrlId) {
			return false;
		}
		if ($this->endDateYmd === null || $other->endDateYmd === null || $this->endDateYmd !== $other->endDateYmd) {
			return false;
		}

		return $this->titlesAlign($other);
	}

	public function matchesTierC(self $other): bool
	{
		if ($this->endDateYmd === null || $other->endDateYmd === null || $this->endDateYmd !== $other->endDateYmd) {
			return false;
		}

		return $this->titlesAlign($other);
	}

	public function titlesAlign(self $other): bool
	{
		foreach ([$this->titleNormalized, $this->rawTitleNormalized] as $left) {
			if ($left === '') {
				continue;
			}
			foreach ([$other->titleNormalized, $other->rawTitleNormalized] as $right) {
				if ($right !== '' && $left === $right) {
					return true;
				}
			}
		}

		return false;
	}

	/** @return list<string> */
	public function urlLookupVariants(): array
	{
		$variants = [];
		foreach ([$this->rawSavedUrl, $this->normalizedDetailUrl] as $url) {
			foreach (self::expandUrlVariants($url) as $variant) {
				$variants[$variant] = $variant;
			}
		}

		return array_values($variants);
	}

	public static function normalizeUrlForMatch(?string $url): string
	{
		$url = strtolower(trim((string) $url));
		if ($url === '') {
			return '';
		}
		$url = preg_replace('#^https?://#', '', $url) ?? $url;
		$url = preg_replace('#^www\.#', '', $url) ?? $url;

		return rtrim($url, '/');
	}

	public static function normalizeTitle(?string $title): string
	{
		$title = trim((string) $title);
		if ($title === '') {
			return '';
		}
		if (str_starts_with(strtolower($title), 'corporate:')) {
			$title = trim(substr($title, strlen('Corporate:')));
		}

		return mb_strtolower(preg_replace('/\s+/u', ' ', $title) ?? $title);
	}

	public static function normalizeSolicitation(mixed $value): string
	{
		return self::normalizeToken($value);
	}

	public static function normalizeToken(mixed $value): string
	{
		if (is_array($value)) {
			$value = implode(' ', $value);
		}
		$s = trim((string) ($value ?? ''));

		return $s === '' ? '' : mb_strtolower($s);
	}

	/** Portal project numbers (e.g. Maui 236220) often land in NAICSCODE during scrape. */
	public static function normalizePortalProjectNaicsKey(mixed $value): string
	{
		if (is_array($value)) {
			$value = implode(' ', $value);
		}
		$s = trim((string) ($value ?? ''));
		if ($s === '' || !preg_match('/^\d{5,8}$/', $s)) {
			return '';
		}

		return mb_strtolower($s);
	}

	public static function normalizeEndDateYmd(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}
		try {
			return Carbon::parse((string) $value)->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return list<string> */
	private static function expandUrlVariants(string $url): array
	{
		$url = trim($url);
		if ($url === '') {
			return [];
		}

		$out = [$url];
		$normalized = self::normalizeUrlForMatch($url);
		if ($normalized !== '') {
			$out[] = $normalized;
			$out[] = 'https://' . $normalized;
			$out[] = 'http://' . $normalized;
			$out[] = 'https://www.' . $normalized;
			$out[] = 'http://www.' . $normalized;
		}

		return array_values(array_unique(array_filter($out, fn (string $v) => $v !== '')));
	}
}
