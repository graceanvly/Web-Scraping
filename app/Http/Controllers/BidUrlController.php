<?php

namespace App\Http\Controllers;

use App\Models\BidUrl;
use App\Models\FailedBidUrl;
use App\Support\BidUrlScrapeMarker;
use App\Support\BidUrlScrapeGroup;
use App\Services\BidReferenceLookupService;
use App\Services\OdsBidUrlAutoAssignService;
use App\Services\OdsBidUrlListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BidUrlController extends Controller
{
    /**
     * Show form to upload file.
     */
    public function create()
    {
        return view('bidurl.upload');
    }

    /**
     * Store data from uploaded text file into bid_url table.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:txt,csv|max:2048',
        ]);

        $file = $request->file('file');
        $lines = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $fields = explode('|', $line);

            if (count($fields) < 11) {
                continue; // skip invalid lines
            }

            $urlValue = trim($fields[0]);
            $nameValue = trim($fields[1]);

            if ($this->urlOrNameExists($urlValue, $nameValue, BidUrlScrapeGroup::default())) {
                continue; // skip duplicates by url or name
            }

            BidUrl::create([
                'url' => $urlValue,
                'name' => $nameValue,
                'start_time' => !empty($fields[2]) ? $fields[2] : null,
                'end_time' => !empty($fields[3]) ? $fields[3] : null,
                'weight' => (int) $fields[4],
                'user_id' => (int) $fields[5],
                'check_changes' => (int) $fields[6],
                'visit_required' => (int) $fields[7],
                'checksum' => (int) $fields[8],
                'valid' => (int) $fields[9],
                'third_party_url_id' => (int) $fields[10],
            ]);
        }

        return redirect()->route('bidurl.index')->with('success', 'File imported successfully!');
    }

    /**
     * Show all BidUrl records.
     */
    public function index(Request $request, BidReferenceLookupService $lookup, OdsBidUrlListingService $odsBidUrls, OdsBidUrlAutoAssignService $odsAutoAssign)
    {
        $perPage = (int) $request->integer('per_page', 50);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $search = trim((string) $request->query('search', ''));
        $scrapeGroupRaw = $request->query('scrape_group');
        if ($scrapeGroupRaw === null) {
            $scrapeGroup = BidUrlScrapeGroup::default();
        } else {
            $scrapeGroup = trim((string) $scrapeGroupRaw);
        }

        $activeTab = $request->query('tab', 'configured');
        if (!in_array($activeTab, ['configured', 'failed', 'unassigned'], true)) {
            $activeTab = 'configured';
        }

        $query = BidUrl::query()->orderBy('id');
        BidUrlScrapeGroup::applyFilter($query, $scrapeGroup);
        $failedQuery = FailedBidUrl::query()->orderByDesc('failed_at')->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('url', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
            $failedQuery->where(function ($q) use ($search) {
                $q->where('url', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('failure_message', 'like', "%{$search}%");
            });
        }

        $bidUrls = $query->paginate($perPage, ['*'], 'page')->withQueryString();
        $failedBidUrls = $failedQuery->paginate($perPage, ['*'], 'failed_page')->withQueryString();
        $unassignedBidUrls = $odsBidUrls->paginateUnassigned($search, $perPage);

        $failedCount = $failedBidUrls->total();
        $unassignedCount = $unassignedBidUrls->total();

        $manilaDirectoryUsers = [];
        try {
            $manilaDirectoryUsers = $lookup->getManilaAssignableUsersForSelect();
        } catch (\Throwable $e) {
            Log::warning('Manila directory users not loaded on Bid URLs page', ['error' => $e->getMessage()]);
        }

        return view('bidurl.index', [
            'bidUrls' => $bidUrls,
            'failedBidUrls' => $failedBidUrls,
            'unassignedBidUrls' => $unassignedBidUrls,
            'search' => $search,
            'failedCount' => $failedCount,
            'unassignedCount' => $unassignedCount,
            'odsBidUrlAvailable' => $odsBidUrls->isAvailable(),
            'odsBidUrlAutoAssignAvailable' => $odsAutoAssign->canAutoAssign(),
            'activeTab' => $activeTab,
            'scrapeGroup' => $scrapeGroup,
            'scrapeGroups' => BidUrlScrapeGroup::distinctGroups(),
            'defaultScrapeGroup' => BidUrlScrapeGroup::default(),
            'manilaDirectoryUsers' => $manilaDirectoryUsers,
        ]);
    }

    public function autoAssignUnassigned(OdsBidUrlAutoAssignService $autoAssign)
    {
        if (!$autoAssign->canAutoAssign()) {
            return redirect()
                ->route('bidurl.index', ['tab' => 'unassigned'])
                ->withErrors(['auto_assign' => 'Auto assign requires ODS BIDURL and BIDURLHISTORY tables.']);
        }

        $stats = $autoAssign->assignAllUnassigned();

        $parts = [];
        if ($stats['assigned'] > 0) {
            $parts[] = "{$stats['assigned']} assigned from visit history";
        }
        if ($stats['skipped_no_history'] > 0) {
            $parts[] = "{$stats['skipped_no_history']} skipped (no history user)";
        }
        if ($stats['skipped_already'] > 0) {
            $parts[] = "{$stats['skipped_already']} already assigned";
        }
        if ($stats['failed'] > 0) {
            $parts[] = "{$stats['failed']} failed";
        }

        $message = $parts !== []
            ? implode('; ', $parts) . '.'
            : 'No unassigned URLs to process.';

        return redirect()
            ->route('bidurl.index', ['tab' => 'unassigned'])
            ->with('success', $message);
    }

    public function downloadUnassigned(Request $request, OdsBidUrlListingService $odsBidUrls)
    {
        if (!$odsBidUrls->isAvailable()) {
            return redirect()
                ->route('bidurl.index', ['tab' => 'unassigned'])
                ->withErrors(['download' => 'ODS BIDURL table is not available.']);
        }

        $search = trim((string) $request->query('search', ''));

        return $odsBidUrls->downloadUnassignedExcel($search);
    }

    /**
     * Store a single BidUrl from the inline form.
     */
    public function storeSingle(Request $request)
    {
        $data = $request->validate([
            'url' => [
                'required',
                'url',
                'max:2048',
                'regex:/^https?:\\/\\//i',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'scrape_group' => ['nullable', 'string', 'max:64'],
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

        $scrapeGroup = $this->resolveScrapeGroupForSave($data['scrape_group'] ?? null);

        $this->ensureUrlAndNameAreAvailable($data['url'], $data['name'] ?? null, null, null, $scrapeGroup);

        if (BidUrlScrapeGroup::hasColumn()) {
            $data['scrape_group'] = $scrapeGroup;
        } else {
            unset($data['scrape_group']);
        }

        BidUrl::create($data);

        return redirect()->route('bidurl.index')->with('success', 'Bid URL added.');
    }

    /**
     * Update an existing BidUrl.
     */
    public function update(Request $request, BidUrl $bidUrl)
    {
        $data = $request->validate([
            'url' => [
                'required',
                'url',
                'max:2048',
                'regex:/^https?:\\/\\//i',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'scrape_group' => ['nullable', 'string', 'max:64'],
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

        $scrapeGroup = $this->resolveScrapeGroupForSave(
            $data['scrape_group'] ?? $bidUrl->scrape_group ?? null
        );

        $this->ensureUrlAndNameAreAvailable($data['url'], $data['name'] ?? null, $bidUrl->id, null, $scrapeGroup);

        if (BidUrlScrapeGroup::hasColumn()) {
            $data['scrape_group'] = $scrapeGroup;
        } else {
            unset($data['scrape_group']);
        }

        $bidUrl->update($data);

        return redirect()->route('bidurl.index')->with('success', 'Bid URL updated.');
    }

    /**
     * Set last_scraped_at for all BidUrls (or clear it on all).
     */
    public function setLastScraped(Request $request)
    {
        $data = $request->validate([
            'last_scraped_at' => ['nullable', 'date', 'required_unless:clear_last_scraped,1'],
            'clear_last_scraped' => ['sometimes', 'boolean'],
            'scrape_group' => ['nullable', 'string', 'max:64'],
        ], [
            'last_scraped_at.required_unless' => 'Choose a date or select Clear last scraped.',
        ]);

        $clear = $request->boolean('clear_last_scraped');
        $scrapeGroup = trim((string) ($data['scrape_group'] ?? ''));

        $chunkQuery = BidUrlScrapeGroup::applyFilter(BidUrl::query(), $scrapeGroup);

        if ($clear) {
            $updated = 0;
            $urlPk = (new BidUrl())->getKeyName();
            $chunkQuery->clone()->orderBy($urlPk)->chunkById(200, function ($rows) use (&$updated) {
                foreach ($rows as $bidUrl) {
                    BidUrlScrapeMarker::clearManualLastScraped($bidUrl);
                    $bidUrl->save();
                    $updated++;
                }
            }, $urlPk);
        } else {
            // Query-builder update bypasses Eloquent casts; Oracle rejects ISO strings like
            // 2026-06-01T13:57 (ORA-01858). Save per row so the scrape marker is written
            // the same way scrapeStream does (now() on individual models).
            $at = Carbon::parse($data['last_scraped_at']);
            $updated = 0;
            $urlPk = (new BidUrl())->getKeyName();
            $chunkQuery->clone()->orderBy($urlPk)->chunkById(200, function ($rows) use ($at, &$updated) {
                foreach ($rows as $bidUrl) {
                    BidUrlScrapeMarker::applyManualLastScraped($bidUrl, $at);
                    $bidUrl->save();
                    $updated++;
                }
            }, $urlPk);
        }

        $message = $clear
            ? "Last scraped cleared for {$updated} Bid URL(s)."
            : "Last scraped updated for {$updated} Bid URL(s).";
        if ($scrapeGroup !== '') {
            $message .= " (group: {$scrapeGroup})";
        }

        return redirect()->route('bidurl.index', array_filter([
            'tab' => 'configured',
            'scrape_group' => $scrapeGroup !== '' ? $scrapeGroup : null,
        ]))->with('success', $message);
    }

    /**
     * Delete a BidUrl.
     */
    public function destroy(BidUrl $bidUrl)
    {
        $bidUrl->delete();

        return redirect()->route('bidurl.index')->with('success', 'Bid URL deleted.');
    }

    /**
     * Show a single BidUrl record.
     */
    public function show(BidUrl $bidUrl)
    {
        return view('bidurl.show', compact('bidUrl'));
    }

    public function restoreFailed(FailedBidUrl $failedBidUrl)
    {
        $this->ensureUrlAndNameAreAvailable(
            $failedBidUrl->url,
            $failedBidUrl->name,
            null,
            $failedBidUrl->id,
            BidUrlScrapeGroup::default(),
        );
        $this->persistRestoredBidUrl($failedBidUrl);

        return redirect()->route('bidurl.index', ['tab' => 'failed'])->with('success', 'Failed URL restored to Bid URLs.');
    }

    /**
     * Move every failed URL back to the active bid_url list (all pages, not just the current table page).
     */
    public function restoreAllFailed(Request $request)
    {
        $restored = 0;
        $alreadyActive = 0;
        $skipped = 0;

        FailedBidUrl::query()->orderBy('id')->chunkById(100, function ($rows) use (&$restored, &$alreadyActive, &$skipped) {
            foreach ($rows as $failedBidUrl) {
                $outcome = $this->restoreFailedRow($failedBidUrl);
                if ($outcome === 'restored') {
                    $restored++;
                } elseif ($outcome === 'already_active') {
                    $alreadyActive++;
                } else {
                    $skipped++;
                }
            }
        });

        if ($restored === 0 && $alreadyActive === 0 && $skipped === 0) {
            return redirect()->route('bidurl.index')->with('success', 'No failed URLs to restore.');
        }

        $parts = [];
        if ($restored > 0) {
            $parts[] = "{$restored} restored to Bid URLs";
        }
        if ($alreadyActive > 0) {
            $parts[] = "{$alreadyActive} already on the active list (removed from failed)";
        }
        if ($skipped > 0) {
            $parts[] = "{$skipped} skipped (duplicate name or other conflict)";
        }

        return redirect()->route('bidurl.index', ['tab' => 'failed'])->with('success', implode('; ', $parts) . '.');
    }

    public function destroyFailed(FailedBidUrl $failedBidUrl)
    {
        $failedBidUrl->delete();

        return redirect()->route('bidurl.index', ['tab' => 'failed'])->with('success', 'Failed URL deleted.');
    }

    /**
     * @return 'restored'|'already_active'|'skipped'
     */
    private function restoreFailedRow(FailedBidUrl $failedBidUrl): string
    {
        if ($this->urlExists($failedBidUrl->url, null, $failedBidUrl->id, BidUrlScrapeGroup::default())) {
            $failedBidUrl->delete();

            return 'already_active';
        }

        try {
            $this->ensureUrlAndNameAreAvailable(
                $failedBidUrl->url,
                $failedBidUrl->name,
                null,
                $failedBidUrl->id,
                BidUrlScrapeGroup::default(),
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return 'skipped';
        }

        $this->persistRestoredBidUrl($failedBidUrl);

        return 'restored';
    }

    private function persistRestoredBidUrl(FailedBidUrl $failedBidUrl): void
    {
        $attrs = [
            'url' => $failedBidUrl->url,
            'name' => $failedBidUrl->name,
            'start_time' => $failedBidUrl->start_time,
            'end_time' => $failedBidUrl->end_time,
            'weight' => $failedBidUrl->weight,
            'user_id' => $failedBidUrl->user_id,
            'check_changes' => $failedBidUrl->check_changes,
            'visit_required' => $failedBidUrl->visit_required,
            'checksum' => $failedBidUrl->checksum,
            'valid' => $failedBidUrl->valid,
            'third_party_url_id' => $failedBidUrl->third_party_url_id,
            'username' => $failedBidUrl->username,
            'password' => $failedBidUrl->password,
        ];

        BidUrl::create(array_merge(
            $attrs,
            BidUrlScrapeMarker::restoreLastScrapedAttributes(
                $failedBidUrl->last_scraped_at,
                $failedBidUrl->end_time
            ),
            BidUrlScrapeGroup::hasColumn() ? ['scrape_group' => BidUrlScrapeGroup::default()] : [],
        ));

        $failedBidUrl->delete();
    }

    private function ensureUrlAndNameAreAvailable(
        string $url,
        ?string $name = null,
        ?int $ignoreBidUrlId = null,
        ?int $ignoreFailedBidUrlId = null,
        ?string $scrapeGroup = null,
    ): void {
        if ($this->urlExists($url, $ignoreBidUrlId, $ignoreFailedBidUrlId, $scrapeGroup)) {
            $message = BidUrlScrapeGroup::hasColumn() && trim((string) ($scrapeGroup ?? '')) !== ''
                ? 'This URL already exists in the active or failed URL list for scrape group "' . trim((string) $scrapeGroup) . '".'
                : 'This URL already exists in the active or failed URL list.';

            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => $message,
            ]);
        }

        if ($name !== null && $name !== '') {
            if ($this->nameExists($name, $ignoreBidUrlId, $ignoreFailedBidUrlId, $scrapeGroup)) {
                $message = BidUrlScrapeGroup::hasColumn() && trim((string) ($scrapeGroup ?? '')) !== ''
                    ? 'This name already exists in the active or failed URL list for scrape group "' . trim((string) $scrapeGroup) . '".'
                    : 'This name already exists in the active or failed URL list.';

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'name' => $message,
                ]);
            }
        }
    }

    private function resolveScrapeGroupForSave(mixed $value): string
    {
        if (!BidUrlScrapeGroup::hasColumn()) {
            return '';
        }

        $group = trim((string) ($value ?? ''));

        return $group !== '' ? $group : BidUrlScrapeGroup::default();
    }

    private function urlOrNameExists(string $url, ?string $name = null, ?string $scrapeGroup = null): bool
    {
        return $this->urlExists($url, null, null, $scrapeGroup)
            || ($name !== null && $name !== '' && $this->nameExists($name, null, null, $scrapeGroup));
    }

    private function urlExists(
        string $url,
        ?int $ignoreBidUrlId = null,
        ?int $ignoreFailedBidUrlId = null,
        ?string $scrapeGroup = null,
    ): bool {
        $activeUrlQuery = BidUrl::where('url', $url);
        if ($ignoreBidUrlId) {
            $activeUrlQuery->where('id', '!=', $ignoreBidUrlId);
        }
        if (BidUrlScrapeGroup::hasColumn()) {
            BidUrlScrapeGroup::applyFilter($activeUrlQuery, $this->resolveScrapeGroupForSave($scrapeGroup));
        }

        $failedUrlQuery = FailedBidUrl::where('url', $url);
        if ($ignoreFailedBidUrlId) {
            $failedUrlQuery->where('id', '!=', $ignoreFailedBidUrlId);
        }

        return $activeUrlQuery->exists() || $failedUrlQuery->exists();
    }

    private function nameExists(
        string $name,
        ?int $ignoreBidUrlId = null,
        ?int $ignoreFailedBidUrlId = null,
        ?string $scrapeGroup = null,
    ): bool {
        $activeNameQuery = BidUrl::where('name', $name);
        if ($ignoreBidUrlId) {
            $activeNameQuery->where('id', '!=', $ignoreBidUrlId);
        }
        if (BidUrlScrapeGroup::hasColumn()) {
            BidUrlScrapeGroup::applyFilter($activeNameQuery, $this->resolveScrapeGroupForSave($scrapeGroup));
        }

        $failedNameQuery = FailedBidUrl::where('name', $name);
        if ($ignoreFailedBidUrlId) {
            $failedNameQuery->where('id', '!=', $ignoreFailedBidUrlId);
        }

        return $activeNameQuery->exists() || $failedNameQuery->exists();
    }
}
