<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidUrlHistory;
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

		usort($rows, function (array $a, array $b) {
			$byBids = ($b['bids_added'] <=> $a['bids_added']);
			if ($byBids !== 0) {
				return $byBids;
			}

			return strcasecmp($a['label'], $b['label']);
		});

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

	/**
	 * Live bids added in range for one user (or all Manila users when $userId is 0).
	 *
	 * @param  list<int>  $manilaUserIds
	 * @return list<array{
	 *     title: string,
	 *     entity: string,
	 *     bid_url: string,
	 *     state: string,
	 *     created: string,
	 *     created_display: string
	 * }>
	 */
	public function bidsAddedListing(
		int $userId,
		Carbon $from,
		Carbon $to,
		array $manilaUserIds,
		BidReferenceLookupService $lookup,
		BidUrlManualEntryService $bidUrls,
	): array {
		$from = $from->copy()->timezone(self::REPORT_TZ)->startOfDay();
		$to = $to->copy()->timezone(self::REPORT_TZ)->endOfDay();

		$userIds = $userId > 0 ? [$userId] : array_values(array_filter($manilaUserIds, fn (int $id) => $id > 0));
		if ($userIds === []) {
			return [];
		}

		$rows = $this->fetchBidsAddedRaw($userIds, $from, $to);
		usort($rows, function (array $a, array $b) {
			return strcmp((string) ($b['created_sort'] ?? ''), (string) ($a['created_sort'] ?? ''));
		});

		$entityLabels = [];
		$stateLabels = [];
		$bidUrlLabels = [];

		$out = [];
		foreach ($rows as $row) {
			$entityId = (int) ($row['entityid'] ?? 0);
			$stateId = (int) ($row['stateid'] ?? 0);
			$bidUrlId = (int) ($row['bid_url_id'] ?? 0);

			if ($entityId > 0 && !array_key_exists($entityId, $entityLabels)) {
				$opt = $lookup->getEntityOptionById($entityId);
				$entityLabels[$entityId] = $opt['label'] ?? ('Entity #' . $entityId);
			}
			if ($stateId > 0 && !array_key_exists($stateId, $stateLabels)) {
				$opt = $lookup->getStateOptionById($stateId);
				$stateLabels[$stateId] = $opt['label'] ?? ('State #' . $stateId);
			}
			if ($bidUrlId > 0 && !array_key_exists($bidUrlId, $bidUrlLabels)) {
				$opt = $bidUrls->getBidUrlOptionById($bidUrlId);
				$bidUrlLabels[$bidUrlId] = $opt['label'] ?? ('Bid URL #' . $bidUrlId);
			}

			$bidUrlLabel = $bidUrlId > 0 ? ($bidUrlLabels[$bidUrlId] ?? '') : '';

			$created = $row['created'] ?? null;
			$createdCarbon = $created instanceof Carbon ? $created : null;
			if ($createdCarbon === null && $created !== null && $created !== '') {
				try {
					$createdCarbon = Carbon::parse((string) $created, self::REPORT_TZ);
				} catch (\Throwable $e) {
					$createdCarbon = null;
				}
			}

			$out[] = [
				'title' => (string) ($row['title'] ?? ''),
				'entity' => $entityId > 0 ? ($entityLabels[$entityId] ?? '—') : '—',
				'bid_url' => $bidUrlLabel !== '' ? $bidUrlLabel : '—',
				'state' => $stateId > 0 ? ($stateLabels[$stateId] ?? '—') : '—',
				'created' => $createdCarbon?->toIso8601String() ?? '',
				'created_display' => $createdCarbon?->timezone(self::REPORT_TZ)->format('M j, Y') ?? '—',
			];
		}

		return $out;
	}

	/**
	 * @param  list<int>  $userIds
	 * @return list<array<string, mixed>>
	 */
	private function fetchBidsAddedRaw(array $userIds, Carbon $from, Carbon $to): array
	{
		$rows = [];

		try {
			$live = Bid::query()
				->whereIn('USERID', $userIds)
				->whereBetween('CREATED', [$from, $to])
				->orderByDesc('CREATED')
				->get(['TITLE', 'ENTITYID', 'STATEID', 'BID_URL_ID', 'CREATED']);

			foreach ($live as $bid) {
				$created = $bid->getAttribute('CREATED');
				$rows[] = [
					'title' => (string) ($bid->getAttribute('TITLE') ?? ''),
					'entityid' => (int) ($bid->getAttribute('ENTITYID') ?? 0),
					'stateid' => (int) ($bid->getAttribute('STATEID') ?? 0),
					'bid_url_id' => (int) ($bid->getAttribute('BID_URL_ID') ?? 0),
					'created' => $created,
					'created_sort' => $created instanceof Carbon ? $created->toIso8601String() : (string) $created,
				];
			}
		} catch (\Throwable $e) {
			Log::warning('BidReportsService: could not list live bids for report', ['error' => $e->getMessage()]);
		}

		return $rows;
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
