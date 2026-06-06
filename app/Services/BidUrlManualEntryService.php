<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Models\BidUrlHistory;
use App\Models\FailedBidUrl;
use App\Models\TempBid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BidUrlManualEntryService
{
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
		return DB::transaction(function () use ($fields, $bidUrl, $startTime, $approve, $actorUserId) {
			$temp = new TempBid();
			$temp->fill($fields);
			$temp->BID_URL_ID = $bidUrl->id;
			$temp->source_listing_url = $bidUrl->url;
			$temp->bid_url_name = $bidUrl->name;
			$temp->CREATED = now();
			$temp->LAST_MODIFIED = now();
			$temp->SUBSCRIPTIONTYPEID = 10;
			$temp->SETASIDECODEID = 1;
			$temp->save();

			$result = 'pending';

			if ($approve) {
				$result = $this->promoteTempBid($temp);
			}

			$this->finishConfigured($bidUrl, $startTime, $actorUserId);

			return $result;
		});
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return 'pending'|'approved'|'duplicate'
	 */
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
		$bid->fill($temp->toLiveBidAttributes());
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
