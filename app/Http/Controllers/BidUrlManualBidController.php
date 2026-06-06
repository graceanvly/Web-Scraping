<?php

namespace App\Http\Controllers;

use App\Models\BidUrl;
use App\Models\FailedBidUrl;
use App\Services\BidUrlManualEntryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class BidUrlManualBidController extends Controller
{
	public function startConfigured(BidUrl $bidUrl, BidUrlManualEntryService $entries)
	{
		$this->guardAddEligible($bidUrl->last_scraped_at);

		$start = $entries->beginConfigured($bidUrl);

		return response()->json([
			'started_at' => $start->toIso8601String(),
			'bid_url_id' => (int) $bidUrl->id,
		]);
	}

	public function startFailed(FailedBidUrl $failedBidUrl, BidUrlManualEntryService $entries)
	{
		$this->guardAddEligible($failedBidUrl->last_scraped_at);

		$start = $entries->beginFailed($failedBidUrl);

		return response()->json([
			'started_at' => $start->toIso8601String(),
			'bid_url_id' => (int) ($failedBidUrl->original_bid_url_id ?? 0),
		]);
	}

	public function storeConfigured(Request $request, BidUrl $bidUrl, BidUrlManualEntryService $entries)
	{
		$this->guardAddEligible($bidUrl->last_scraped_at);
		[$fields, $startTime, $approve] = $this->parseManualBidRequest($request);

		$result = $entries->saveManualBid($fields, $bidUrl, $startTime, $approve, Auth::id());

		return redirect()->route('bidurl.index', $request->only(['search', 'per_page', 'page', 'failed_page']))
			->with('success', $this->resultMessage($result, $approve));
	}

	public function storeFailed(Request $request, FailedBidUrl $failedBidUrl, BidUrlManualEntryService $entries)
	{
		$this->guardAddEligible($failedBidUrl->last_scraped_at);
		[$fields, $startTime, $approve] = $this->parseManualBidRequest($request);

		$result = $entries->saveManualBidForFailed($fields, $failedBidUrl, $startTime, $approve, Auth::id());

		return redirect()->route('bidurl.index', $request->only(['search', 'per_page', 'page', 'failed_page']))
			->with('success', $this->resultMessage($result, $approve));
	}

	public function cancelConfigured(Request $request, BidUrl $bidUrl, BidUrlManualEntryService $entries)
	{
		$startTime = $this->parseStartedAt($request);
		$entries->finishConfigured($bidUrl, $startTime, Auth::id());

		return response()->json(['ok' => true]);
	}

	public function cancelFailed(Request $request, FailedBidUrl $failedBidUrl, BidUrlManualEntryService $entries)
	{
		$startTime = $this->parseStartedAt($request);
		$entries->finishFailed($failedBidUrl, $startTime, Auth::id());

		return response()->json(['ok' => true]);
	}

	public function searchBidUrls(Request $request, BidUrlManualEntryService $entries)
	{
		$userId = (int) $request->query('user_id', BidUrlManualEntryService::DEFAULT_BID_URL_USER_ID);
		$query = trim((string) $request->query('q', ''));
		$resolveId = (int) $request->query('id', 0);

		if ($resolveId > 0) {
			$row = BidUrl::query()
				->where('id', $resolveId)
				->where('user_id', $userId)
				->first();

			if (!$row) {
				return response()->json(['resolved' => null]);
			}

			$url = trim((string) ($row->url ?? ''));
			$name = trim((string) ($row->name ?? ''));

			return response()->json([
				'resolved' => [
					'id' => (int) $row->id,
					'label' => $name !== '' ? ($name . ' — ' . $url) : $url,
					'url' => $url,
				],
			]);
		}

		return response()->json([
			'results' => $entries->searchAssignedBidUrls($userId, $query),
		]);
	}

	public function startFromBids(Request $request, BidUrlManualEntryService $entries)
	{
		$bidUrlId = (int) $request->input('bid_url_id', 0);
		$listingUrl = trim((string) $request->input('listing_url', ''));
		$bidUrl = $entries->resolveBidUrlForManualEntry($bidUrlId > 0 ? $bidUrlId : null, $listingUrl);

		if ($listingUrl === '' && !$bidUrl) {
			abort(422, 'Enter or select a listing URL.');
		}

		if ($bidUrl) {
			$this->guardAddEligible($bidUrl->last_scraped_at);
			$listingUrl = trim((string) ($bidUrl->url ?? $listingUrl));
		}

		$start = $entries->beginManualEntry($bidUrl);

		return response()->json([
			'started_at' => $start->toIso8601String(),
			'bid_url_id' => $bidUrl ? (int) $bidUrl->id : null,
			'listing_url' => $listingUrl,
		]);
	}

	public function storeFromBids(Request $request, BidUrlManualEntryService $entries)
	{
		[$fields, $startTime, $approve] = $this->parseManualBidRequest($request);
		$bidUrlId = (int) $request->input('bid_url_id', 0);
		$listingUrl = trim((string) $request->input('listing_url', ''));
		$bidUrl = $entries->resolveBidUrlForManualEntry($bidUrlId > 0 ? $bidUrlId : null, $listingUrl);

		if ($bidUrl) {
			$this->guardAddEligible($bidUrl->last_scraped_at);
			$listingUrl = trim((string) ($bidUrl->url ?? $listingUrl));
		} elseif ($listingUrl === '') {
			abort(422, 'Listing URL is required.');
		}

		$result = $entries->saveManualBidEntry($fields, $bidUrl, $startTime, $approve, Auth::id(), $listingUrl);

		return redirect()->route('bids.index', $request->only(['userid', 'per_page', 'search', 'tab']))
			->with('success', $this->resultMessage($result, $approve));
	}

	public function cancelFromBids(Request $request, BidUrlManualEntryService $entries)
	{
		$startTime = $this->parseStartedAt($request);
		$bidUrlId = (int) $request->input('bid_url_id', 0);
		$listingUrl = trim((string) $request->input('listing_url', ''));
		$bidUrl = $entries->resolveBidUrlForManualEntry($bidUrlId > 0 ? $bidUrlId : null, $listingUrl);
		$entries->finishManualEntry($bidUrl, $startTime, Auth::id());

		return response()->json(['ok' => true]);
	}

	private function guardAddEligible($lastScrapedAt): void
	{
		$at = $lastScrapedAt ? Carbon::parse($lastScrapedAt) : null;
		if (!BidUrlManualEntryService::showAddButton($at)) {
			abort(403, 'Manual add is only available when this URL was not scraped today.');
		}
	}

	private function parseStartedAt(Request $request): Carbon
	{
		$raw = trim((string) $request->input('started_at', ''));
		if ($raw === '') {
			abort(422, 'Missing manual entry start time.');
		}

		return Carbon::parse($raw);
	}

	/** @return array{0: array<string, mixed>, 1: Carbon, 2: bool} */
	private function parseManualBidRequest(Request $request): array
	{
		$validated = $this->validatedBidFields($request);
		$startTime = Carbon::parse($validated['started_at']);
		$approve = $request->boolean('approve');
		unset($validated['started_at']);

		return [$validated, $startTime, $approve];
	}

	/** @return array<string, mixed> */
	private function validatedBidFields(Request $request): array
	{
		foreach (['DESCRIPTION', 'EMAIL', 'URL', 'NAICSCODE'] as $key) {
			if ($request->input($key) === '') {
				$request->merge([$key => null]);
			}
		}
		foreach (['ENTITYID', 'CATEGORYID', 'STATEID', 'USERID'] as $key) {
			if ($request->input($key) === '' || $request->input($key) === null) {
				$request->merge([$key => null]);
			}
		}
		if ($request->input('ENDDATE') === '') {
			$request->merge(['ENDDATE' => null]);
		}

		return $request->validate([
			'TITLE' => ['required', 'string', 'max:255'],
			'DESCRIPTION' => ['nullable', 'string'],
			'EMAIL' => ['nullable', 'email', 'max:255'],
			'URL' => ['nullable', 'string', 'max:500'],
			'ENDDATE' => ['nullable', 'date'],
			'NAICSCODE' => ['nullable', 'string', 'max:255'],
			'ENTITYID' => ['nullable', 'integer'],
			'CATEGORYID' => ['nullable', 'integer'],
			'STATEID' => ['nullable', 'integer'],
			'USERID' => ['nullable', 'integer'],
			'started_at' => ['required', 'date'],
		], [
			'TITLE.required' => 'Title is required.',
		]);
	}

	private function resultMessage(string $result, bool $approve): string
	{
		if ($result === 'duplicate') {
			return 'That bid is already live.';
		}
		if ($approve && $result === 'approved') {
			return 'Bid added and published to the live table.';
		}

		return 'Bid added to pending approval.';
	}
}
