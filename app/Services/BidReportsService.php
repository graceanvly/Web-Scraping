<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidUrlHistory;
use App\Models\TempBid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BidReportsService
{
	private const REPORT_TZ = 'Asia/Manila';

	/**
	 * @param array<int, array{id: int|string, label: string}> $manilaUsers
	 * @return array{
	 *     rows: array<int, array{user_id: int, label: string, bids_added: int, urls_visited: int, bids_today: int}>,
	 *     totals: array{bids_added: int, urls_visited: int, bids_today: int},
	 *     from: Carbon,
	 *     to: Carbon,
	 *     today: Carbon
	 * }
	 */
	public function userActivityReport(array $manilaUsers, Carbon $from, Carbon $to): array
	{
		$from = $from->copy()->timezone(self::REPORT_TZ)->startOfDay();
		$to = $to->copy()->timezone(self::REPORT_TZ)->endOfDay();
		$today = now(self::REPORT_TZ)->startOfDay();
		$todayEnd = $today->copy()->endOfDay();

		$manilaIds = collect($manilaUsers)
			->map(fn (array $u) => (int) $u['id'])
			->filter(fn (int $id) => $id > 0)
			->values()
			->all();

		$bidsInRange = $this->countBidsGroupedByUser($manilaIds, $from, $to);
		$bidsToday = $this->countBidsGroupedByUser($manilaIds, $today, $todayEnd);
		$urlsInRange = $this->countUrlVisitsGroupedByUser($manilaIds, $from, $to);

		$rows = [];
		foreach ($manilaUsers as $user) {
			$userId = (int) $user['id'];
			if ($userId <= 0) {
				continue;
			}
			$rows[] = [
				'user_id' => $userId,
				'label' => (string) ($user['label'] ?? ('User #' . $userId)),
				'bids_added' => (int) ($bidsInRange[$userId] ?? 0),
				'urls_visited' => (int) ($urlsInRange[$userId] ?? 0),
				'bids_today' => (int) ($bidsToday[$userId] ?? 0),
			];
		}

		usort($rows, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

		return [
			'rows' => $rows,
			'totals' => [
				'bids_added' => array_sum(array_column($rows, 'bids_added')),
				'urls_visited' => array_sum(array_column($rows, 'urls_visited')),
				'bids_today' => array_sum(array_column($rows, 'bids_today')),
			],
			'from' => $from,
			'to' => $to,
			'today' => $today,
		];
	}

	public function defaultReportRange(): array
	{
		$now = now(self::REPORT_TZ);

		return [
			'from' => $now->copy()->startOfMonth(),
			'to' => $now->copy()->endOfMonth(),
		];
	}

	/** @return array<int, int> user_id => count */
	private function countBidsGroupedByUser(array $manilaIds, Carbon $from, Carbon $to): array
	{
		if ($manilaIds === []) {
			return [];
		}

		$counts = [];

		try {
			$live = Bid::query()
				->whereIn('USERID', $manilaIds)
				->whereBetween('CREATED', [$from, $to])
				->selectRaw('USERID as user_id, COUNT(*) as aggregate_count')
				->groupBy('USERID')
				->pluck('aggregate_count', 'user_id');

			foreach ($live as $userId => $count) {
				$uid = (int) $userId;
				$counts[$uid] = ($counts[$uid] ?? 0) + (int) $count;
			}
		} catch (\Throwable $e) {
			Log::warning('BidReportsService: could not count live bids', ['error' => $e->getMessage()]);
		}

		if (!Schema::hasTable((new TempBid())->getTable())) {
			return $counts;
		}

		try {
			$pending = TempBid::query()
				->whereIn('USERID', $manilaIds)
				->whereBetween('CREATED', [$from, $to])
				->selectRaw('USERID as user_id, COUNT(*) as aggregate_count')
				->groupBy('USERID')
				->pluck('aggregate_count', 'user_id');

			foreach ($pending as $userId => $count) {
				$uid = (int) $userId;
				$counts[$uid] = ($counts[$uid] ?? 0) + (int) $count;
			}
		} catch (\Throwable $e) {
			Log::warning('BidReportsService: could not count pending bids', ['error' => $e->getMessage()]);
		}

		return $counts;
	}

	/** @return array<int, int> user_id => count */
	private function countUrlVisitsGroupedByUser(array $manilaIds, Carbon $from, Carbon $to): array
	{
		if ($manilaIds === [] || !Schema::hasTable((new BidUrlHistory())->getTable())) {
			return [];
		}

		try {
			return BidUrlHistory::query()
				->whereIn('user_id', $manilaIds)
				->whereBetween('start_time', [$from, $to])
				->selectRaw('user_id, COUNT(*) as aggregate_count')
				->groupBy('user_id')
				->pluck('aggregate_count', 'user_id')
				->map(fn ($count) => (int) $count)
				->all();
		} catch (\Throwable $e) {
			Log::warning('BidReportsService: could not count URL visits', ['error' => $e->getMessage()]);

			return [];
		}
	}
}
