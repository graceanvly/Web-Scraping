<?php

namespace App\Services;

use App\Models\OdsBidUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class OdsBidUrlListingService
{
	public function isAvailable(): bool
	{
		$table = (string) config('scraper.ods_bidurl_table', 'BIDURL');

		try {
			return Schema::hasTable($table);
		} catch (\Throwable) {
			return false;
		}
	}

	public function paginateUnassigned(string $search, int $perPage): LengthAwarePaginator
	{
		$perPage = max(5, min(200, $perPage));

		if (!$this->isAvailable()) {
			return $this->emptyPage($perPage);
		}

		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');
		$urlCol = (string) config('scraper.ods_bidurl_url_column', 'URL');
		$nameCol = (string) config('scraper.ods_bidurl_name_column', 'NAME');
		$userCol = (string) config('scraper.ods_bidurl_user_id_column', 'USER_ID');

		try {
			$query = OdsBidUrl::query()
				->where(function ($q) use ($userCol) {
					$q->whereNull($userCol)->orWhere($userCol, 0);
				})
				->orderBy($idCol);

			$search = trim($search);
			if ($search !== '') {
				$query->where(function ($q) use ($search, $urlCol, $nameCol) {
					$q->where($urlCol, 'like', "%{$search}%")
						->orWhere($nameCol, 'like', "%{$search}%");
				});
			}

			return $query->paginate($perPage, ['*'], 'unassigned_page')->withQueryString();
		} catch (\Throwable $e) {
			Log::warning('ODS BIDURL unassigned listing failed', ['error' => $e->getMessage()]);

			return $this->emptyPage($perPage);
		}
	}

	private function emptyPage(int $perPage): LengthAwarePaginator
	{
		return new Paginator(
			[],
			0,
			$perPage,
			1,
			['path' => request()->url(), 'pageName' => 'unassigned_page']
		);
	}
}
