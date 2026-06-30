<?php

namespace App\Services;

use App\Models\OdsBidUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class OdsBidUrlAutoAssignService
{
	public function __construct(
		private readonly OdsBidUrlListingService $listing,
	) {
	}

	public function isHistoryAvailable(): bool
	{
		$table = (string) config('scraper.ods_bidurl_history_table', 'BIDURLHISTORY');

		try {
			return Schema::hasTable($table);
		} catch (\Throwable) {
			return false;
		}
	}

	public function canAutoAssign(): bool
	{
		return $this->listing->isAvailable() && $this->isHistoryAvailable();
	}

	/**
	 * @return array{assigned: int, skipped_no_history: int, skipped_already: int, failed: int}
	 */
	public function assignAllUnassigned(): array
	{
		$stats = [
			'assigned' => 0,
			'skipped_no_history' => 0,
			'skipped_already' => 0,
			'failed' => 0,
		];

		if (!$this->canAutoAssign()) {
			return $stats;
		}

		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');
		$userCol = (string) config('scraper.ods_bidurl_user_id_column', 'USER_ID');
		$odsTable = (string) config('scraper.ods_bidurl_table', 'BIDURL');

		OdsBidUrl::query()
			->where(function ($q) use ($userCol) {
				$q->whereNull($userCol)->orWhere($userCol, 0);
			})
			->orderBy($idCol)
			->chunkById(100, function ($rows) use (&$stats, $idCol, $userCol, $odsTable) {
				$ids = $rows->map(fn (OdsBidUrl $row) => (int) $row->getKey())->filter()->values()->all();
				$userByBidUrlId = $this->latestUserIdByBidUrlId($ids);

				foreach ($rows as $row) {
					$bidUrlId = (int) $row->getKey();
					if ($bidUrlId <= 0) {
						continue;
					}

					$userId = $userByBidUrlId[$bidUrlId] ?? null;
					if ($userId === null || $userId <= 0) {
						$stats['skipped_no_history']++;

						continue;
					}

					try {
						$updated = DB::table($odsTable)
							->where($idCol, $bidUrlId)
							->where(function ($q) use ($userCol) {
								$q->whereNull($userCol)->orWhere($userCol, 0);
							})
							->update([$userCol => $userId]);

						if ($updated > 0) {
							$stats['assigned']++;
							Log::info('ODS BIDURL auto-assigned from history', [
								'bid_url_id' => $bidUrlId,
								'user_id' => $userId,
							]);
						} else {
							$stats['skipped_already']++;
						}
					} catch (\Throwable $e) {
						$stats['failed']++;
						Log::warning('ODS BIDURL auto-assign failed for row', [
							'bid_url_id' => $bidUrlId,
							'user_id' => $userId,
							'error' => $e->getMessage(),
						]);
					}
				}
			}, $idCol);

		return $stats;
	}

	/**
	 * @param  list<int>  $bidUrlIds
	 * @return array<int, int>
	 */
	public function latestUserIdByBidUrlId(array $bidUrlIds): array
	{
		$bidUrlIds = array_values(array_filter(array_map('intval', $bidUrlIds), fn (int $id) => $id > 0));
		if ($bidUrlIds === []) {
			return [];
		}

		$table = (string) config('scraper.ods_bidurl_history_table', 'BIDURLHISTORY');
		$bidUrlCol = (string) config('scraper.ods_bidurl_history_bid_url_id_column', 'BID_URL_ID');
		$userCol = (string) config('scraper.ods_bidurl_history_user_id_column', 'USER_ID');
		$endCol = (string) config('scraper.ods_bidurl_history_end_time_column', 'END_TIME');
		$startCol = (string) config('scraper.ods_bidurl_history_start_time_column', 'START_TIME');
		$idCol = (string) config('scraper.ods_bidurl_history_id_column', 'ID');

		$rows = DB::table($table)
			->whereIn($bidUrlCol, $bidUrlIds)
			->whereNotNull($userCol)
			->where($userCol, '!=', 0)
			->orderByDesc($endCol)
			->orderByDesc($startCol)
			->orderByDesc($idCol)
			->get([$bidUrlCol, $userCol]);

		$map = [];
		foreach ($rows as $row) {
			$bidId = (int) $this->readColumn($row, $bidUrlCol);
			if ($bidId <= 0 || isset($map[$bidId])) {
				continue;
			}

			$map[$bidId] = (int) $this->readColumn($row, $userCol);
		}

		return $map;
	}

	private function readColumn(object $row, string $column): mixed
	{
		foreach ((array) $row as $key => $value) {
			if (strcasecmp((string) $key, $column) === 0) {
				return $value;
			}
		}

		return $row->{$column} ?? null;
	}
}
