<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\TempBid;
use App\Services\BidReferenceLookupService;
use App\Services\PendingSimilarEntriesService;
use App\Support\BidDetailPayload;
use App\Support\BidLiveWriter;
use App\Support\PendingBidLiveMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Review queue for scraped bids. Rows live in `bids_temp` until a user approves
 * them (copied into the live `bid` table) or rejects them (deleted).
 */
class PendingBidController extends Controller
{
	public function index(Request $request, BidReferenceLookupService $lookup)
	{
		$perPage = (int) $request->integer('per_page', 50);
		if (!in_array($perPage, [10, 25, 50, 100, 200], true)) {
			$perPage = 50;
		}

		$search = trim((string) $request->query('search', ''));

		$query = TempBid::query();
		if ($search !== '') {
			$query->where(function ($q) use ($search) {
				$q->where('TITLE', 'like', "%{$search}%")
					->orWhere('URL', 'like', "%{$search}%")
					->orWhere('bid_url_name', 'like', "%{$search}%")
					->orWhere('NAICSCODE', 'like', "%{$search}%");
			});
		}

		$pending = $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();

		// Resolve a display label for each distinct ENTITYID on this page.
		$entityLabels = [];
		foreach ($pending as $row) {
			$eid = (int) ($row->ENTITYID ?? 0);
			if ($eid > 0 && !array_key_exists($eid, $entityLabels)) {
				try {
					$opt = $lookup->getEntityOptionById($eid);
					$entityLabels[$eid] = $opt['label'] ?? ('Entity #' . $eid);
				} catch (\Throwable $e) {
					$entityLabels[$eid] = 'Entity #' . $eid;
				}
			}
		}

		$manilaDirectoryUsers = [];
		try {
			$manilaDirectoryUsers = $lookup->getManilaAssignableUsersForSelect();
		} catch (\Throwable $e) {
			Log::warning('Manila directory users not loaded', ['error' => $e->getMessage()]);
		}

		$pendingTotal = TempBid::count();

		return view('pending.index', compact(
			'pending',
			'pendingTotal',
			'entityLabels',
			'manilaDirectoryUsers',
			'search'
		));
	}

	/**
	 * JSON: last 5 similar live/pending bids (entity → email → URL).
	 */
	public function similar(Request $request, PendingSimilarEntriesService $similar)
	{
		return response()->json($similar->find(
			(int) $request->query('entity_id', 0),
			$request->query('email'),
			$request->query('url'),
			(int) $request->query('exclude_temp_id', 0),
		));
	}

	/** JSON for detail modals (similar pending bids). */
	public function showJson(TempBid $pendingBid, BidReferenceLookupService $lookup)
	{
		return response()->json(BidDetailPayload::fromPending($pendingBid, $lookup));
	}

	public function update(Request $request, TempBid $pendingBid)
	{
		$this->applyEditableFields($request, $pendingBid);
		$this->applyReferenceIdsFromRequest($request, $pendingBid);
		$pendingBid->save();

		return redirect()->route('pending.index', $request->only(['search', 'per_page', 'page']))
			->with('success', 'Pending bid updated.');
	}

	public function approve(Request $request, TempBid $pendingBid)
	{
		// Edit modal sets edit_modal=1 and posts ENTITYID, STATEID, BID_URL_ID, etc.
		// Row-level "Approve" only carries entity/user (or nothing).
		if ($request->boolean('edit_modal') || $request->has('TITLE')) {
			$this->applyEditableFields($request, $pendingBid);
			$this->applyReferenceIdsFromRequest($request, $pendingBid);
			$pendingBid->save();
		} else {
			if ($request->filled('ENTITYID') && is_numeric($request->input('ENTITYID'))) {
				$pendingBid->ENTITYID = (int) $request->input('ENTITYID');
			}
			if ($request->filled('USERID') && is_numeric($request->input('USERID'))) {
				$pendingBid->USERID = (int) $request->input('USERID');
			}
		}

		$referenceIds = $this->extractReferenceIds($request);

		try {
			$result = $this->promoteToLive(
				$pendingBid,
				$request->boolean('edit_modal') || $request->has('TITLE'),
				$referenceIds,
			);
		} catch (\RuntimeException $e) {
			return redirect()->route('pending.index', $request->only(['search', 'per_page', 'page']))
				->with('error', $e->getMessage());
		}

		$msg = $result === 'duplicate'
			? 'That bid is already live — removed from the queue.'
			: 'Bid approved and published.';

		return redirect()->route('pending.index', $request->only(['search', 'per_page', 'page']))
			->with('success', $msg);
	}

	public function reject(Request $request, TempBid $pendingBid)
	{
		$pendingBid->delete();

		return redirect()->route('pending.index', $request->only(['search', 'per_page', 'page']))
			->with('success', 'Pending bid rejected.');
	}

	public function approveAll(Request $request)
	{
		$bidUrlId = $request->input('bid_url_id');

		$query = TempBid::query();
		if ($bidUrlId !== null && $bidUrlId !== '' && ctype_digit((string) $bidUrlId)) {
			$query->where('BID_URL_ID', (int) $bidUrlId);
		}

		$approved = 0;
		$duplicates = 0;
		$failed = 0;

		$query->orderBy('id')->chunkById(100, function ($rows) use (&$approved, &$duplicates, &$failed) {
			foreach ($rows as $row) {
				try {
					$result = $this->promoteToLive($row);
					$result === 'duplicate' ? $duplicates++ : $approved++;
				} catch (\Throwable $e) {
					$failed++;
					Log::error('Approve-all failed for pending bid', ['id' => $row->id, 'error' => $e->getMessage()]);
				}
			}
		});

		$parts = [];
		if ($approved > 0) {
			$parts[] = "{$approved} approved";
		}
		if ($duplicates > 0) {
			$parts[] = "{$duplicates} already live (removed)";
		}
		if ($failed > 0) {
			$parts[] = "{$failed} failed";
		}
		$msg = $parts === [] ? 'No pending bids to approve.' : implode(', ', $parts) . '.';

		return redirect()->route('pending.index')->with('success', $msg);
	}

	public function rejectAll(Request $request)
	{
		$bidUrlId = $request->input('bid_url_id');

		$query = TempBid::query();
		if ($bidUrlId !== null && $bidUrlId !== '' && ctype_digit((string) $bidUrlId)) {
			$query->where('BID_URL_ID', (int) $bidUrlId);
		}

		$deleted = $query->delete();

		return redirect()->route('pending.index')->with('success', "{$deleted} pending bid(s) rejected.");
	}

	/** Validate and apply the editable fields from the review form onto a pending bid. */
	private function applyEditableFields(Request $request, TempBid $pendingBid): void
	{
		foreach (['DESCRIPTION', 'EMAIL', 'URL', 'NAICSCODE'] as $key) {
			if ($request->input($key) === '') {
				$request->merge([$key => null]);
			}
		}
		foreach (['ENTITYID', 'CATEGORYID', 'STATEID', 'USERID', 'BID_URL_ID'] as $key) {
			if ($request->input($key) === '' || $request->input($key) === null) {
				$request->merge([$key => null]);
			}
		}
		if ($request->input('ENDDATE') === '') {
			$request->merge(['ENDDATE' => null]);
		}

		$validated = $request->validate([
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
			'BID_URL_ID' => ['nullable', 'integer'],
		], [
			'TITLE.required' => 'Title is required.',
			'ENDDATE.date' => 'End date must be a valid date.',
		]);

		$validated['LAST_MODIFIED'] = now();
		foreach (['ENTITYID', 'CATEGORYID', 'STATEID', 'USERID', 'BID_URL_ID'] as $key) {
			if (array_key_exists($key, $validated) && $validated[$key] !== null) {
				$validated[$key] = (int) $validated[$key];
			}
		}
		$pendingBid->fill($validated);
	}

	/** Apply ENTITYID / STATEID / BID_URL_ID from the edit form even if temp save omits them. */
	private function applyReferenceIdsFromRequest(Request $request, TempBid $pendingBid): void
	{
		foreach (PendingBidLiveMapper::REFERENCE_ID_COLUMNS as $key) {
			if (!$request->has($key)) {
				continue;
			}
			$raw = $request->input($key);
			if ($raw === null || $raw === '') {
				$pendingBid->setAttribute($key, null);

				continue;
			}
			if (is_numeric($raw)) {
				$pendingBid->setAttribute($key, (int) $raw);
			}
		}
	}

	/**
	 * @return array<string, int|null>
	 */
	private function extractReferenceIds(Request $request): array
	{
		$out = [];
		foreach (PendingBidLiveMapper::REFERENCE_ID_COLUMNS as $key) {
			if (!$request->has($key)) {
				continue;
			}
			$raw = $request->input($key);
			if ($raw === null || $raw === '') {
				$out[$key] = null;
			} elseif (is_numeric($raw)) {
				$out[$key] = (int) $raw;
			}
		}

		return $out;
	}

	private function applyPendingAttrsToLiveBid(TempBid $pendingBid, Bid $existing, array $referenceIds = []): void
	{
		$attrs = PendingBidLiveMapper::attributesForInsert($pendingBid, $referenceIds);
		BidLiveWriter::applyAttributes($existing, PendingBidLiveMapper::withoutPrimaryKey($attrs));
		$existing->save();
		BidLiveWriter::patchReferenceIds($existing, $attrs);
	}

	/**
	 * Copy a pending bid into the live `bid` table and remove it from the queue.
	 * Returns 'approved' or 'duplicate' (already live — temp row is dropped either way).
	 */
	private function promoteToLive(TempBid $pendingBid, bool $fromEditModal = false, array $referenceIds = []): string
	{
		return DB::transaction(function () use ($pendingBid, $fromEditModal, $referenceIds) {
			$existing = $this->findMatchingLiveBid($pendingBid);
			if ($existing !== null) {
				if ($fromEditModal) {
					$this->applyPendingAttrsToLiveBid($pendingBid, $existing, $referenceIds);
					Log::info('Pending approve: updated existing live bid from edit modal', [
						'temp_id' => $pendingBid->id,
						'live_id' => $existing->getKey(),
						'entityid' => $existing->getAttribute('ENTITYID'),
						'stateid' => $existing->getAttribute('STATEID'),
						'bid_url_id' => $existing->getAttribute('BID_URL_ID'),
					]);
				}
				$pendingBid->delete();

				return 'duplicate';
			}

			$attrs = PendingBidLiveMapper::attributesForInsert($pendingBid, $referenceIds);

			Log::info('Pending bid promote attrs', [
				'temp_id' => $pendingBid->id,
				'request_entityid' => $referenceIds['ENTITYID'] ?? null,
				'request_stateid' => $referenceIds['STATEID'] ?? null,
				'request_bid_url_id' => $referenceIds['BID_URL_ID'] ?? null,
				'entityid' => $attrs['ENTITYID'] ?? null,
				'stateid' => $attrs['STATEID'] ?? null,
				'bid_url_id' => $attrs['BID_URL_ID'] ?? null,
				'categoryid' => $attrs['CATEGORYID'] ?? null,
			]);

			$bid = new Bid();
			BidLiveWriter::applyAttributes($bid, PendingBidLiveMapper::withoutPrimaryKey($attrs));
			$bid->save();
			BidLiveWriter::patchReferenceIds($bid, $attrs);

			Log::info('Pending bid promoted to live', [
				'temp_id' => $pendingBid->id,
				'live_id' => $bid->getKey(),
				'entityid' => $bid->getAttribute('ENTITYID'),
				'stateid' => $bid->getAttribute('STATEID'),
				'bid_url_id' => $bid->getAttribute('BID_URL_ID'),
			]);

			$pendingBid->delete();

			return 'approved';
		});
	}

	private function findMatchingLiveBid(TempBid $pendingBid): ?Bid
	{
		$title = (string) ($pendingBid->TITLE ?? '');
		if ($title === '') {
			return null;
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
			->first();
	}

	private function liveBidExists(TempBid $pendingBid): bool
	{
		return $this->findMatchingLiveBid($pendingBid) !== null;
	}
}
