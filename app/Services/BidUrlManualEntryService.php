<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Models\BidUrlHistory;
use App\Models\FailedBidUrl;
use App\Models\TempBid;
use App\Support\PendingBidLiveMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BidUrlManualEntryService
{
	public const DEFAULT_BID_URL_USER_ID = 120482;

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

		return BidUrl::where('url', $url)->first();
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	public function getBidUrlOptionById(int $id): ?array
	{
		if ($id < 1) {
			return null;
		}

		$row = BidUrl::find($id);

		return $row ? $this->bidUrlToSelectOption($row) : null;
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	private function bidUrlToSelectOption(object $row): ?array
	{
		$url = trim((string) ($row->url ?? ''));
		if ($url === '') {
			return null;
		}
		$name = trim((string) ($row->name ?? ''));
		$idRaw = $row->id ?? $row->ID ?? null;
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
		$builder = BidUrl::query();
		if ($userId !== null && $userId > 0) {
			$builder->where('user_id', $userId);
		}

		$query = trim($query);
		if ($query !== '') {
			$builder->where(function ($sub) use ($query) {
				$sub->where('url', 'like', '%' . $query . '%')
					->orWhere('name', 'like', '%' . $query . '%');
			});
		}

		$out = [];
		foreach ($builder->orderBy('name')->orderBy('url')->limit($limit)->get() as $row) {
			$opt = $this->bidUrlToSelectOption($row);
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
			return BidUrl::find($bidUrlId);
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
			$bidUrl->start_time = $startTime;
			$bidUrl->end_time = $end;
			$bidUrl->last_scraped_at = $end;
			$bidUrl->save();
		} else {
			BidUrl::where('id', $bidUrlId)->update([
				'start_time' => $startTime,
				'end_time' => $end,
				'last_scraped_at' => $end,
			]);
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

		return BidUrl::where('url', $url)->first();
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
		BidUrlHistory::create([
			'bid_url_id' => $bidUrlId,
			'start_time' => $startTime,
			'end_time' => $endTime,
			'user_id' => $userId,
		]);
	}

	/** @return 'approved'|'duplicate' */
	private function promoteTempBid(TempBid $temp): string
	{
		if ($this->liveBidExists($temp)) {
			$temp->delete();

			return 'duplicate';
		}

		$bid = new Bid();
		$bid->forceFill(PendingBidLiveMapper::withoutPrimaryKey(
			PendingBidLiveMapper::attributesForInsert($temp)
		));
		$bid->LAST_MODIFIED = now();
		$bid->save();
		$temp->delete();

		return 'approved';
	}

	private function liveBidExists(TempBid $pendingBid): bool
	{
		$title = (string) ($pendingBid->TITLE ?? '');
		if ($title === '') {
			return false;
		}

		$url = (string) ($pendingBid->URL ?? '');
		$endDate = $pendingBid->ENDDATE ? (string) $pendingBid->ENDDATE : null;

		return Bid::where('TITLE', $title)
			->where(function ($q) use ($url, $endDate) {
				if ($url === '') {
					$q->where(function ($q2) {
						$q2->whereNull('URL')->orWhere('URL', '');
					});
				} else {
					$q->where('URL', $url);
				}
				if ($endDate) {
					$q->orWhere('ENDDATE', $endDate);
				}
			})
			->exists();
	}
}
