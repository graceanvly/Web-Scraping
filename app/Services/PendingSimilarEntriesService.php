<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\TempBid;
use Illuminate\Support\Carbon;

/**
 * Finds recent live / pending bids similar to a row under review (entity → email → URL).
 */
class PendingSimilarEntriesService
{
	private const LIMIT = 5;

	/**
	 * @return array{match_type: string|null, match_label: string, entries: list<array<string, mixed>>}
	 */
	public function find(int $entityId, ?string $email, ?string $url, int $excludeTempId = 0): array
	{
		$emailNorm = strtolower(trim((string) $email));
		$urlNorm = $this->normalizeUrlForMatch($url);

		if ($entityId > 0) {
			$entries = $this->collectMatches(entityId: $entityId, excludeTempId: $excludeTempId);
			if ($entries !== []) {
				return $this->payload('entity', 'Matched by entity', $entries);
			}
		}

		if ($emailNorm !== '' && filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
			$entries = $this->collectMatches(email: $emailNorm, excludeTempId: $excludeTempId);
			if ($entries !== []) {
				return $this->payload('email', 'Matched by contact email', $entries);
			}
		}

		if ($urlNorm !== '') {
			$entries = $this->collectMatches(urlNorm: $urlNorm, urlRaw: trim((string) $url), excludeTempId: $excludeTempId);
			if ($entries !== []) {
				return $this->payload('url', 'Matched by listing URL', $entries);
			}
		}

		return $this->payload(null, 'No similar bids found', []);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function collectMatches(
		int $entityId = 0,
		string $email = '',
		string $urlNorm = '',
		string $urlRaw = '',
		int $excludeTempId = 0,
	): array {
		$merged = [];

		foreach ($this->queryLive($entityId, $email, $urlNorm, $urlRaw) as $row) {
			$merged[] = $row;
		}
		foreach ($this->queryPending($entityId, $email, $urlNorm, $urlRaw, $excludeTempId) as $row) {
			$merged[] = $row;
		}

		usort($merged, static function (array $a, array $b): int {
			return ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0);
		});

		$seen = [];
		$out = [];
		foreach ($merged as $row) {
			$key = ($row['source'] ?? '') . ':' . ($row['id'] ?? '');
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			unset($row['sort_ts']);
			$out[] = $row;
			if (count($out) >= self::LIMIT) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function queryLive(int $entityId, string $email, string $urlNorm, string $urlRaw): array
	{
		$q = Bid::query();
		$this->applyMatchFilters($q, $entityId, $email, $urlNorm, $urlRaw);

		try {
			$rows = $q->orderByDesc('CREATED')->limit(self::LIMIT * 2)->get();
		} catch (\Throwable) {
			return [];
		}

		return $rows->map(fn (Bid $bid) => $this->mapLiveRow($bid))->all();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function queryPending(int $entityId, string $email, string $urlNorm, string $urlRaw, int $excludeTempId): array
	{
		$q = TempBid::query();
		if ($excludeTempId > 0) {
			$q->where('id', '!=', $excludeTempId);
		}
		$this->applyMatchFilters($q, $entityId, $email, $urlNorm, $urlRaw);

		try {
			$rows = $q->orderByDesc('created_at')->limit(self::LIMIT * 2)->get();
		} catch (\Throwable) {
			return [];
		}

		return $rows->map(fn (TempBid $bid) => $this->mapPendingRow($bid))->all();
	}

	/**
	 * @param \Illuminate\Database\Eloquent\Builder<Bid|\App\Models\TempBid> $q
	 */
	private function applyMatchFilters($q, int $entityId, string $email, string $urlNorm, string $urlRaw): void
	{
		if ($entityId > 0) {
			$q->where('ENTITYID', $entityId);

			return;
		}

		if ($email !== '') {
			$q->whereRaw('LOWER(TRIM(EMAIL)) = ?', [$email]);

			return;
		}

		if ($urlNorm !== '') {
			$q->where(function ($w) use ($urlNorm, $urlRaw) {
				$w->whereRaw('LOWER(TRIM(URL)) = ?', [$urlNorm]);
				if ($urlRaw !== '' && $urlRaw !== $urlNorm) {
					$w->orWhereRaw('LOWER(TRIM(URL)) = ?', [strtolower(trim($urlRaw))]);
				}
			});
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mapLiveRow(Bid $bid): array
	{
		$id = $bid->ID ?? $bid->id ?? null;
		$created = $this->parseSortTime($bid->CREATED ?? $bid->LAST_MODIFIED ?? null);

		return [
			'id' => $id,
			'source' => 'live',
			'title' => trim((string) ($bid->TITLE ?? '')) ?: 'Untitled',
			'end_date' => $this->formatShortDate($bid->ENDDATE ?? null),
			'url' => trim((string) ($bid->URL ?? '')),
			'email' => trim((string) ($bid->EMAIL ?? '')),
			'scraped' => $this->formatShortDate($bid->CREATED ?? null),
			'sort_ts' => $created,
			'view_url' => $id ? route('bids.show', ['bid' => $id]) : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mapPendingRow(TempBid $bid): array
	{
		$created = $this->parseSortTime($bid->created_at ?? $bid->CREATED ?? null);

		return [
			'id' => $bid->id,
			'source' => 'pending',
			'title' => trim((string) ($bid->TITLE ?? '')) ?: 'Untitled',
			'end_date' => $this->formatShortDate($bid->ENDDATE ?? null),
			'url' => trim((string) ($bid->URL ?? '')),
			'email' => trim((string) ($bid->EMAIL ?? '')),
			'scraped' => $this->formatShortDate($bid->created_at ?? null),
			'sort_ts' => $created,
			'view_url' => null,
		];
	}

	private function normalizeUrlForMatch(?string $url): string
	{
		$url = strtolower(trim((string) $url));
		if ($url === '') {
			return '';
		}
		$url = preg_replace('#^https?://#', '', $url) ?? $url;
		$url = preg_replace('#^www\.#', '', $url) ?? $url;

		return rtrim($url, '/');
	}

	private function parseSortTime(mixed $value): int
	{
		if ($value === null || $value === '') {
			return 0;
		}
		try {
			return Carbon::parse($value)->getTimestamp();
		} catch (\Throwable) {
			return 0;
		}
	}

	private function formatShortDate(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}
		try {
			return Carbon::parse($value)->format('n/j/Y');
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 * @return array{match_type: string|null, match_label: string, entries: list<array<string, mixed>>}
	 */
	private function payload(?string $matchType, string $matchLabel, array $entries): array
	{
		return [
			'match_type' => $matchType,
			'match_label' => $matchLabel,
			'entries' => $entries,
		];
	}
}
