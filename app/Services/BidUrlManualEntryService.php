<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Models\BidUrlHistory;
use App\Models\FailedBidUrl;
use App\Models\TempBid;
use App\Services\BidDuplicateMatcher;
use App\Support\BidIdentity;
use App\Support\BidLiveWriter;
use App\Support\BidUrlScrapeMarker;
use App\Support\BidUrlTableConfig;
use App\Support\PendingBidLiveMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BidUrlManualEntryService
{
	public const DEFAULT_BID_URL_USER_ID = 120482;

	/** Cached result of whether the configured visit-history table exists. */
	private static ?bool $historyTableAvailable = null;

	private function cfg(string $key, mixed $default = null): mixed
	{
		return config("scraper.{$key}", $default);
	}

	private function rowAttr(object $row, string $column): mixed
	{
		foreach ((array) $row as $key => $value) {
			if (strcasecmp((string) $key, $column) === 0) {
				return $value;
			}
		}

		return null;
	}

	/** @return array{table: string, id_col: string, url_col: string, name_col: string, user_col: string} */
	private function bidUrlTableSpec(): array
	{
		return [
			'table' => BidUrlTableConfig::table(),
			'id_col' => (string) $this->cfg('bid_url_id_column', 'id'),
			'url_col' => (string) $this->cfg('bid_url_url_column', 'url'),
			'name_col' => (string) $this->cfg('bid_url_name_column', 'name'),
			'user_col' => (string) $this->cfg('bid_url_user_id_column', 'user_id'),
		];
	}

	public static function showAddButton(?Carbon $lastScrapedAt): bool
	{
		return $lastScrapedAt === null || !$lastScrapedAt->isToday();
	}

	public function beginConfigured(BidUrl $bidUrl): Carbon
	{
		$start = now();
		$bidUrl->start_time = $start;
		$bidUrl->save();

		return $start;
	}

	public function beginFailed(FailedBidUrl $failedBidUrl): Carbon
	{
		$start = now();
		$failedBidUrl->start_time = $start;
		$failedBidUrl->save();

		$active = $this->findActiveBidUrlByUrl($failedBidUrl->url);
		if ($active) {
			$active->start_time = $start;
			$active->save();
		}

		return $start;
	}

	public function finishConfigured(BidUrl $bidUrl, Carbon $startTime, ?int $userId): void
	{
		$this->finishScrapeVisit((int) $bidUrl->id, $startTime, $userId, $bidUrl);
	}

	public function findConfiguredByUrl(?string $url): ?BidUrl
	{
		$url = trim((string) $url);
		if ($url === '') {
			return null;
		}

		$spec = $this->bidUrlTableSpec();

		return BidUrl::query()->where($spec['url_col'], $url)->first();
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	public function getBidUrlOptionById(int $id): ?array
	{
		if ($id < 1) {
			return null;
		}

		$spec = $this->bidUrlTableSpec();

		try {
			$row = DB::table($spec['table'])
				->select([$spec['id_col'], $spec['url_col'], $spec['name_col']])
				->where($spec['id_col'], $id)
				->first();
		} catch (\Throwable $e) {
			Log::warning('BidUrl lookup by id failed', [
				'table' => $spec['table'],
				'id_col' => $spec['id_col'],
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return null;
		}

		return $row ? $this->bidUrlRowToSelectOption($row) : null;
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	private function bidUrlRowToSelectOption(object $row): ?array
	{
		$spec = $this->bidUrlTableSpec();
		$url = trim((string) ($this->rowAttr($row, $spec['url_col']) ?? ''));
		if ($url === '') {
			return null;
		}
		$name = trim((string) ($this->rowAttr($row, $spec['name_col']) ?? ''));
		$idRaw = $this->rowAttr($row, $spec['id_col']);
		if ($idRaw === null || $idRaw === '') {
			return null;
		}

		return [
			'id' => (int) $idRaw,
			'label' => $name !== '' ? ($name . ' — ' . $url) : $url,
			'url' => $url,
		];
	}

	/**
	 * @return array<int, array{id: int, label: string, url: string}>
	 */
	public function searchBidUrlsForSelect(string $query, int $limit = 40, ?int $userId = null): array
	{
		$limit = max(5, min(100, $limit));
		$spec = $this->bidUrlTableSpec();

		try {
			$builder = DB::table($spec['table'])
				->select([$spec['id_col'], $spec['url_col'], $spec['name_col']]);
			if ($userId !== null && $userId > 0) {
				$builder->where($spec['user_col'], $userId);
			}

			$query = trim($query);
			if ($query !== '') {
				$builder->where(function ($sub) use ($query, $spec) {
					$sub->where($spec['url_col'], 'like', '%' . $query . '%')
						->orWhere($spec['name_col'], 'like', '%' . $query . '%');
				});
			}

			$rows = $builder
				->orderBy($spec['name_col'])
				->orderBy($spec['url_col'])
				->limit($limit)
				->get();
		} catch (\Throwable $e) {
			Log::warning('BidUrl search failed', [
				'table' => $spec['table'],
				'error' => $e->getMessage(),
			]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$opt = $this->bidUrlRowToSelectOption($row);
			if ($opt !== null) {
				$out[] = $opt;
			}
		}

		return $out;
	}

	/**
	 * @return array<int, array{id: int, label: string, url: string}>
	 */
	public function searchAssignedBidUrls(int $userId, string $query, int $limit = 40): array
	{
		if ($userId <= 0) {
			return [];
		}

		return $this->searchBidUrlsForSelect($query, $limit, $userId);
	}

	public function resolveBidUrlForManualEntry(?int $bidUrlId, ?string $listingUrl): ?BidUrl
	{
		if ($bidUrlId !== null && $bidUrlId > 0) {
			$spec = $this->bidUrlTableSpec();

			return BidUrl::query()->where($spec['id_col'], $bidUrlId)->first();
		}

		return $this->findConfiguredByUrl($listingUrl);
	}

	public function beginManualEntry(?BidUrl $bidUrl): Carbon
	{
		if ($bidUrl) {
			return $this->beginConfigured($bidUrl);
		}

		return now();
	}

	public function finishManualEntry(?BidUrl $bidUrl, Carbon $startTime, ?int $userId): void
	{
		if ($bidUrl) {
			$this->finishConfigured($bidUrl, $startTime, $userId);
		}
	}

	public function finishScrapeVisit(int $bidUrlId, Carbon $startTime, ?int $userId, ?BidUrl $bidUrl = null): void
	{
		if ($bidUrlId <= 0) {
			return;
		}

		$end = now();

		if ($bidUrl !== null && $bidUrl->exists) {
			BidUrlScrapeMarker::applyFinishScrapeTimes($bidUrl, $startTime, $end);
			$bidUrl->save();
		} else {
			$urlPk = (new BidUrl())->getKeyName();
			BidUrl::where($urlPk, $bidUrlId)->update(
				BidUrlScrapeMarker::finishScrapeUpdateAttributes($startTime, $end)
			);
		}

		FailedBidUrl::where('original_bid_url_id', $bidUrlId)->update([
			'start_time' => $startTime,
			'end_time' => $end,
			'last_scraped_at' => $end,
		]);

		$this->recordHistory($bidUrlId, $startTime, $end, $userId);
	}

	public function finishFailed(FailedBidUrl $failedBidUrl, Carbon $startTime, ?int $userId): void
	{
		$end = now();
		$failedBidUrl->start_time = $startTime;
		$failedBidUrl->end_time = $end;
		$failedBidUrl->save();

		$active = $this->findActiveBidUrlByUrl($failedBidUrl->url);
		if ($active) {
			$active->start_time = $startTime;
			$active->end_time = $end;
			$active->save();
		}

		$historyBidUrlId = $this->resolveHistoryBidUrlId($failedBidUrl, $active);
		if ($historyBidUrlId !== null) {
			$this->recordHistory($historyBidUrlId, $startTime, $end, $userId);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return 'pending'|'approved'|'duplicate'
	 */
	public function saveManualBid(
		array $fields,
		BidUrl $bidUrl,
		Carbon $startTime,
		bool $approve,
		?int $actorUserId
	): string {
		return $this->saveManualBidEntry($fields, $bidUrl, $startTime, $approve, $actorUserId, null);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return 'pending'|'approved'|'duplicate'
	 */
	public function saveManualBidEntry(
		array $fields,
		?BidUrl $bidUrl,
		Carbon $startTime,
		bool $approve,
		?int $actorUserId,
		?string $listingUrl = null
	): string {
		return DB::transaction(function () use ($fields, $bidUrl, $startTime, $approve, $actorUserId, $listingUrl) {
			$temp = new TempBid();
			$temp->fill($fields);
			if ($bidUrl) {
				$temp->BID_URL_ID = $bidUrl->id;
				$temp->source_listing_url = $bidUrl->url;
				$temp->bid_url_name = $bidUrl->name;
			} else {
				$temp->source_listing_url = trim((string) ($listingUrl ?? $fields['URL'] ?? ''));
			}
			$temp->CREATED = now();
			$temp->LAST_MODIFIED = now();
			$temp->SUBSCRIPTIONTYPEID = 10;
			$temp->SETASIDECODEID = 1;

			$dup = app(BidDuplicateMatcher::class)->match(BidIdentity::fromTempBid($temp));
			if ($dup !== null && $dup->shouldSkipSave()) {
				return 'duplicate';
			}

			$temp->save();

			$result = 'pending';

			if ($approve) {
				$result = $this->promoteTempBid($temp);
			}

			$this->finishManualEntry($bidUrl, $startTime, $actorUserId);

			return $result;
		});
	}

	public function saveManualBidForFailed(
		array $fields,
		FailedBidUrl $failedBidUrl,
		Carbon $startTime,
		bool $approve,
		?int $actorUserId
	): string {
		return DB::transaction(function () use ($fields, $failedBidUrl, $startTime, $approve, $actorUserId) {
			$active = $this->findActiveBidUrlByUrl($failedBidUrl->url);
			$bidUrlId = $active?->id ?? $failedBidUrl->original_bid_url_id;

			$temp = new TempBid();
			$temp->fill($fields);
			$temp->BID_URL_ID = $bidUrlId;
			$temp->source_listing_url = $failedBidUrl->url;
			$temp->bid_url_name = $failedBidUrl->name;
			$temp->CREATED = now();
			$temp->LAST_MODIFIED = now();
			$temp->SUBSCRIPTIONTYPEID = 10;
			$temp->SETASIDECODEID = 1;

			$dup = app(BidDuplicateMatcher::class)->match(BidIdentity::fromTempBid($temp));
			if ($dup !== null && $dup->shouldSkipSave()) {
				return 'duplicate';
			}

			$temp->save();

			$result = 'pending';

			if ($approve) {
				$result = $this->promoteTempBid($temp);
			}

			$this->finishFailed($failedBidUrl, $startTime, $actorUserId);

			return $result;
		});
	}

	private function findActiveBidUrlByUrl(?string $url): ?BidUrl
	{
		$url = trim((string) $url);
		if ($url === '') {
			return null;
		}

		$spec = $this->bidUrlTableSpec();

		return BidUrl::query()->where($spec['url_col'], $url)->first();
	}

	private function resolveHistoryBidUrlId(FailedBidUrl $failedBidUrl, ?BidUrl $active): ?int
	{
		if ($active) {
			return (int) $active->id;
		}

		$original = (int) ($failedBidUrl->original_bid_url_id ?? 0);

		return $original > 0 ? $original : null;
	}

	private function recordHistory(int $bidUrlId, Carbon $startTime, Carbon $endTime, ?int $userId): void
	{
		if (!$this->historyTableEnabled()) {
			return;
		}

		try {
			BidUrlHistory::create([
				'bid_url_id' => $bidUrlId,
				'start_time' => $startTime,
				'end_time' => $endTime,
				'user_id' => $userId,
			]);
		} catch (\Throwable $e) {
			if ($this->isMissingHistoryTableError($e)) {
				self::$historyTableAvailable = false;
				Log::warning('bid_url_history unavailable — skipping visit history', [
					'bid_url_id' => $bidUrlId,
					'error' => $e->getMessage(),
				]);

				return;
			}

			throw $e;
		}
	}

	private function historyTableEnabled(): bool
	{
		if (self::$historyTableAvailable !== null) {
			return self::$historyTableAvailable;
		}

		try {
			self::$historyTableAvailable = Schema::hasTable((new BidUrlHistory())->getTable());
		} catch (\Throwable $e) {
			self::$historyTableAvailable = false;
			Log::warning('bid_url_history table check failed — visit history disabled', [
				'error' => $e->getMessage(),
			]);
		}

		return self::$historyTableAvailable;
	}

	private function isMissingHistoryTableError(\Throwable $e): bool
	{
		$msg = $e->getMessage();

		return str_contains($msg, 'ORA-00942')
			|| str_contains($msg, 'does not exist')
			|| str_contains($msg, 'Base table or view not found');
	}

	/** @return 'approved'|'duplicate' */
	private function promoteTempBid(TempBid $temp): string
	{
		$matcher = app(BidDuplicateMatcher::class);
		$existing = $matcher->findMatchingLiveBid($temp);
		if ($existing !== null) {
			if ($matcher->shouldPatchLiveOnDuplicate($temp, $existing)) {
				$attrs = PendingBidLiveMapper::attributesForInsert($temp);
				BidLiveWriter::applyAttributes($existing, PendingBidLiveMapper::withoutPrimaryKey($attrs));
				$existing->LAST_MODIFIED = now();
				$existing->save();
				BidLiveWriter::patchReferenceIds($existing, $attrs);
			}
			$temp->delete();

			return 'duplicate';
		}

		$bid = new Bid();
		$attrs = PendingBidLiveMapper::attributesForInsert($temp);
		BidLiveWriter::applyAttributes($bid, PendingBidLiveMapper::withoutPrimaryKey($attrs));
		$bid->LAST_MODIFIED = now();
		$bid->save();
		BidLiveWriter::patchReferenceIds($bid, $attrs);
		$temp->delete();

		return 'approved';
	}
}
