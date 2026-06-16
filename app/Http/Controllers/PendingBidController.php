<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\TempBid;
use App\Services\BidReferenceLookupService;
use App\Services\PendingSimilarEntriesService;
use App\Support\BidDetailPayload;
use App\Support\BidLiveColumnFilter;
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
		$pendingBid->save();

		return redirect()->route('pending.index', $request->only(['search', 'per_page', 'page']))
			->with('success', 'Pending bid updated.');
	}

	public function approve(Request $request, TempBid $pendingBid)
	{
		// "Save & approve" comes from the edit modal and carries the full field set;
		// a plain row "Approve" only carries entity/user (or nothing).
		if ($request->has('TITLE')) {
			$this->applyEditableFields($request, $pendingBid);
			$pendingBid->save();
		} else {
			if ($request->filled('ENTITYID') && is_numeric($request->input('ENTITYID'))) {
				$pendingBid->ENTITYID = (int) $request->input('ENTITYID');
			}
			if ($request->filled('USERID') && is_numeric($request->input('USERID'))) {
				$pendingBid->USERID = (int) $request->input('USERID');
			}
		}

		$result = $this->promoteToLive($pendingBid);

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
		foreach (['ENTITYID', 'CATEGORYID', 'STATEID', 'USERID'] as $key) {
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
		], [
			'TITLE.required' => 'Title is required.',
			'ENDDATE.date' => 'End date must be a valid date.',
		]);

		$validated['LAST_MODIFIED'] = now();
		$pendingBid->fill($validated);
	}

	/**
	 * Copy a pending bid into the live `bid` table and remove it from the queue.
	 * Returns 'approved' or 'duplicate' (already live — temp row is dropped either way).
	 */
	private function promoteToLive(TempBid $pendingBid): string
	{
		return DB::transaction(function () use ($pendingBid) {
			if ($this->liveBidExists($pendingBid)) {
				$pendingBid->delete();

				return 'duplicate';
			}

			$attrs = BidLiveColumnFilter::filter($pendingBid->toLiveBidAttributes());
			$attrs['LAST_MODIFIED'] = now();

			// Bid's creating hook assigns the Oracle sequence id + production defaults.
			$bid = new Bid();
			$bid->fill($attrs);
			$bid->save();

			$pendingBid->delete();

			return 'approved';
		});
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
