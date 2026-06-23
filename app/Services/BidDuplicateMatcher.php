<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\TempBid;
use App\Support\BidDuplicateMatch;
use App\Support\BidIdentity;
use App\Support\BidLiveColumnFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class BidDuplicateMatcher
{
	/**
	 * @param  int|string|null  $excludeTempId
	 * @param  int|string|null  $excludeLiveId
	 */
	public function match(
		BidIdentity $identity,
		int|string|null $excludeTempId = null,
		int|string|null $excludeLiveId = null,
	): ?BidDuplicateMatch {
		try {
			// Pending table is small; live Oracle bid can be huge — check pending first.
			$pending = $this->findBestMatchOnQuery(TempBid::query(), $identity, 'bids_temp', $excludeTempId);
			if ($pending !== null) {
				return $pending;
			}

			return $this->findBestMatchOnQuery(Bid::query(), $identity, 'bid', $excludeLiveId);
		} catch (\Throwable $e) {
			Log::warning('Bid duplicate lookup failed (fail-open — allowing save)', [
				'error' => $e->getMessage(),
				'url' => $identity->normalizedDetailUrl,
				'solicitation' => $identity->solicitationNumber,
				'bid_url_id' => $identity->bidUrlId,
			]);

			return null;
		}
	}

	public function findMatchingLiveBid(TempBid $pendingBid): ?Bid
	{
		$identity = BidIdentity::fromTempBid($pendingBid);
		$match = $this->match($identity, excludeTempId: $pendingBid->getKey());

		if ($match === null || $match->table !== 'bid' || !$match->model instanceof Bid) {
			return null;
		}

		return $match->model;
	}

	public function liveBidExists(TempBid $pendingBid): bool
	{
		return $this->findMatchingLiveBid($pendingBid) !== null;
	}

	public function shouldPatchLiveOnDuplicate(TempBid $pending, Bid $live): bool
	{
		$pendingDesc = strlen(trim((string) ($pending->DESCRIPTION ?? '')));
		$liveDesc = strlen(trim((string) ($live->DESCRIPTION ?? '')));
		if ($pendingDesc > $liveDesc + 50) {
			return true;
		}

		try {
			$pendingAt = $pending->LAST_MODIFIED ?? $pending->CREATED ?? $pending->created_at;
			$liveAt = $live->LAST_MODIFIED ?? $live->CREATED;
			if ($pendingAt && $liveAt) {
				return \Illuminate\Support\Carbon::parse($pendingAt)->gt(\Illuminate\Support\Carbon::parse($liveAt));
			}
		} catch (\Throwable) {
			return false;
		}

		return false;
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findBestMatchOnQuery(
		Builder $query,
		BidIdentity $identity,
		string $tableLabel,
		int|string|null $excludeId,
	): ?BidDuplicateMatch {
		if ($excludeId !== null && $excludeId !== '') {
			$query->where($query->getModel()->getKeyName(), '!=', $excludeId);
		}

		$tierA = $this->findTierA($query->clone(), $identity, $tableLabel);
		if ($tierA !== null) {
			return $tierA;
		}

		$tierB = $this->findTierB($query->clone(), $identity, $tableLabel);
		if ($tierB !== null) {
			return $tierB;
		}

		return $this->findTierC($query->clone(), $identity, $tableLabel);
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findTierA(Builder $query, BidIdentity $identity, string $tableLabel): ?BidDuplicateMatch
	{
		if ($identity->hasStrongUrlForTierA()) {
			$row = $this->findRowByUrl($query->clone(), $identity);
			if ($row instanceof Model) {
				return $this->buildMatch(BidDuplicateMatch::TIER_A, $tableLabel, $row, 'normalized_url');
			}
		}

		if ($identity->solicitationNumber !== '') {
			$row = $this->findRowBySolicitation($query->clone(), $identity->solicitationNumber);
			if ($row instanceof Model) {
				return $this->buildMatch(BidDuplicateMatch::TIER_A, $tableLabel, $row, 'solicitation_number');
			}
		}

		if ($identity->thirdPartyId !== '' && BidLiveColumnFilter::hasColumn('THIRD_PARTY_IDENTIFIER')) {
			$row = $query->clone()
				->whereRaw('LOWER(TRIM(THIRD_PARTY_IDENTIFIER)) = ?', [$identity->thirdPartyId])
				->limit(1)
				->first();
			if ($row instanceof Model) {
				return $this->buildMatch(BidDuplicateMatch::TIER_A, $tableLabel, $row, 'third_party_identifier');
			}
		}

		return null;
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findRowByUrl(Builder $query, BidIdentity $identity): ?Model
	{
		$normalized = $identity->normalizedDetailUrl;
		if ($normalized === '') {
			return null;
		}

		if ($identity->bidUrlId > 0) {
			$query->where('BID_URL_ID', $identity->bidUrlId);
		}

		$raw = strtolower(trim($identity->rawSavedUrl));

		return $query->where(function (Builder $w) use ($normalized, $raw): void {
			$w->whereRaw('LOWER(TRIM(URL)) = ?', [$normalized]);
			if ($raw !== '' && $raw !== $normalized) {
				$w->orWhereRaw('LOWER(TRIM(URL)) = ?', [$raw]);
			}
		})->limit(1)->first();
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findRowBySolicitation(Builder $query, string $solicitationNumber): ?Model
	{
		$column = BidLiveColumnFilter::resolveColumnName('SOLICITATIONNUMBER')
			?? BidLiveColumnFilter::resolveColumnName('SOLICIATIONNUMBER');
		if ($column === null) {
			return null;
		}

		return $query
			->whereRaw('LOWER(TRIM(' . $column . ')) = ?', [$solicitationNumber])
			->limit(1)
			->first();
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findTierB(Builder $query, BidIdentity $identity, string $tableLabel): ?BidDuplicateMatch
	{
		if ($identity->bidUrlId < 1 || $identity->endDateYmd === null) {
			return null;
		}

		$candidates = $query->clone()
			->where('BID_URL_ID', $identity->bidUrlId)
			->whereDate('ENDDATE', $identity->endDateYmd)
			->limit(40)
			->get();

		foreach ($candidates as $row) {
			$candidate = $row instanceof Bid
				? BidIdentity::fromLiveBid($row)
				: BidIdentity::fromTempBid($row);
			if ($identity->matchesTierB($candidate)) {
				return $this->buildMatch(BidDuplicateMatch::TIER_B, $tableLabel, $row, 'bid_url_enddate_title');
			}
		}

		return null;
	}

	/**
	 * @param  Builder<Bid|TempBid>  $query
	 */
	private function findTierC(Builder $query, BidIdentity $identity, string $tableLabel): ?BidDuplicateMatch
	{
		if ($identity->endDateYmd === null) {
			return null;
		}

		$candidateQuery = $query->clone()->whereDate('ENDDATE', $identity->endDateYmd);
		if ($identity->bidUrlId > 0) {
			$candidateQuery->where('BID_URL_ID', $identity->bidUrlId);
		}

		$candidates = $candidateQuery->limit(60)->get();

		foreach ($candidates as $row) {
			$candidate = $row instanceof Bid
				? BidIdentity::fromLiveBid($row)
				: BidIdentity::fromTempBid($row);
			if ($identity->matchesTierC($candidate)) {
				return $this->buildMatch(BidDuplicateMatch::TIER_C, $tableLabel, $row, 'title_enddate');
			}
		}

		return null;
	}

	private function buildMatch(string $tier, string $tableLabel, Model $row, string $reason): BidDuplicateMatch
	{
		return new BidDuplicateMatch(
			tier: $tier,
			table: $tableLabel,
			recordId: $row->getKey() ?? 0,
			reason: $reason,
			model: $row,
		);
	}
}
