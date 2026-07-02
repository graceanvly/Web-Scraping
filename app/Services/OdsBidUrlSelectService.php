<?php

namespace App\Services;

use App\Models\OdsBidUrl;
use App\Support\LiveBidBidUrlIdResolver;
use Illuminate\Support\Facades\Log;

/** Searchable ODS BIDURL options for pending approve / live bid edit (BID_URL_ID picker). */
final class OdsBidUrlSelectService
{
	public function __construct(
		private readonly OdsBidUrlListingService $listing,
	) {
	}

	public function isAvailable(): bool
	{
		return $this->listing->isAvailable();
	}

	/**
	 * @return array{id: int, label: string, url: string}|null
	 */
	public function getOptionById(int $id): ?array
	{
		return LiveBidBidUrlIdResolver::lookupOptionById($id);
	}

	/**
	 * @return array<int, array{id: int, label: string, url: string}>
	 */
	public function searchForSelect(string $query, int $limit = 40): array
	{
		if (!$this->isAvailable()) {
			return [];
		}

		$limit = max(5, min(100, $limit));
		$idCol = (string) config('scraper.ods_bidurl_id_column', 'ID');
		$urlCol = (string) config('scraper.ods_bidurl_url_column', 'URL');
		$nameCol = (string) config('scraper.ods_bidurl_name_column', 'NAME');

		try {
			$builder = OdsBidUrl::query()->select([$idCol, $urlCol, $nameCol]);
			$query = trim($query);
			if ($query !== '') {
				$builder->where(function ($sub) use ($query, $idCol, $urlCol, $nameCol) {
					$sub->where($urlCol, 'like', '%' . $query . '%')
						->orWhere($nameCol, 'like', '%' . $query . '%');
					if (ctype_digit($query)) {
						$sub->orWhere($idCol, (int) $query);
					}
				});
			}

			$rows = $builder
				->orderBy($nameCol)
				->orderBy($urlCol)
				->limit($limit)
				->get();
		} catch (\Throwable $e) {
			Log::warning('ODS BIDURL select search failed', ['error' => $e->getMessage()]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$opt = $this->getOptionById((int) $row->getKey());
			if ($opt !== null) {
				$out[] = $opt;
			}
		}

		return $out;
	}
}
