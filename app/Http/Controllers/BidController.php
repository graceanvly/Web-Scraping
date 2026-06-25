<?php

namespace App\Http\Controllers;

use App\Exceptions\ScrapeAbortedException;
use App\Models\Bid;
use App\Models\BidUrl;
use App\Models\FailedBidUrl;
use App\Models\ScrapeLog;
use App\Models\TempBid;
use Illuminate\Database\Eloquent\Model;
use App\Services\AIExtractor;
use App\Services\BidDuplicateMatcher;
use App\Services\BidDuplicateRunTracker;
use App\Services\BidReferenceLookupService;
use App\Services\BidReportsService;
use App\Services\BidUrlManualEntryService;
use App\Services\ScraperService;
use App\Support\BidDetailPayload;
use App\Support\BidDuplicateMatch;
use App\Support\BidIdentity;
use App\Support\BidLiveColumnFilter;
use App\Support\BidRecordPayload;
use App\Support\BidUrlScrapeMarker;
use App\Support\ThirdPartyProcurementPortalUrl;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BidController extends Controller
{
	public function index(Request $request)
	{
		$perPage = (int) $request->integer('per_page', 50);
		if (!in_array($perPage, [5, 10, 25, 50, 100], true)) {
			$perPage = 50;
		}

		if (!$request->has('userid')) {
			return redirect()->route('bids.index', array_merge($request->query(), [
				'userid' => '120482',
			]));
		}

		$search = trim((string) $request->query('search', ''));
		$filterDate = trim((string) $request->query('date', ''));
		$filterUserIdRaw = trim((string) ($request->query('userid')));
		$showAll = $request->boolean('all');
		$includeHistorical = $request->boolean('historical');
		$bidListingRecentDays = (int) config('scraper.bid_listing_recent_days', 180);

		$hasScrapedBidUrls = BidUrlScrapeMarker::hasScrapedBidUrls();

		$scopedToScrapedBidUrlExists = static function (\Illuminate\Database\Eloquent\Builder $outer) {
			return BidUrlScrapeMarker::scopeBidListingToScrapedBidUrls($outer);
		};

		if (!$hasScrapedBidUrls) {
			$bids = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
			$bids->withQueryString();
			$noEntityBids = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
			$noEntityBids->withQueryString();
			$noCategoryBids = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
			$noCategoryBids->withQueryString();
		} else {
			$applyBidListingFilters = function ($q) use ($search, $filterDate, $filterUserIdRaw, $includeHistorical, $bidListingRecentDays) {
				if (!$includeHistorical && $bidListingRecentDays > 0) {
					$q->where('CREATED', '>=', now()->subDays($bidListingRecentDays)->startOfDay());
				}
				if ($search !== '') {
					$q->where(function ($q2) use ($search) {
						$q2->where('TITLE', 'like', "%{$search}%")
							->orWhere('NAICSCODE', 'like', "%{$search}%")
							->orWhere('URL', 'like', "%{$search}%");
					});
				}
				if ($filterDate !== '') {
					$q->whereDate('CREATED', $filterDate);
				}
				if ($filterUserIdRaw !== '' && ctype_digit($filterUserIdRaw)) {
					$q->where('USERID', (int) $filterUserIdRaw);
				}
			};

			$query = $scopedToScrapedBidUrlExists(Bid::query());
			$applyBidListingFilters($query);
			$bids = $query->latest('CREATED')->paginate($perPage)->withQueryString();

			// Missing-entity/category lists: same assignee/search/date + 180-day CREATED window as the
			// main Bids tab, but do not require BID_URL_ID ∈ bid_url (live Oracle rows often lack that link).
			$queryNoEntity = Bid::query();
			$this->applyBidMissingNumericIdFilter($queryNoEntity, 'ENTITYID');
			$applyBidListingFilters($queryNoEntity);
			$noEntityBids = $queryNoEntity->latest('CREATED')->paginate($perPage, ['*'], 'ne_page')->withQueryString();

			$queryNoCategory = Bid::query();
			$this->applyBidMissingNumericIdFilter($queryNoCategory, 'CATEGORYID');
			$applyBidListingFilters($queryNoCategory);
			$noCategoryBids = $queryNoCategory->latest('CREATED')->paginate($perPage, ['*'], 'nc_page')->withQueryString();
		}

		$issueCount = 0;
		$scrapeLogs = collect();
		try {
			$issueCount = ScrapeLog::count();
			$scrapeLogs = ScrapeLog::latest('created_at')->limit(200)->get();
		} catch (\Throwable $e) {
			Log::warning('Could not load scrape logs', ['error' => $e->getMessage()]);
		}

		$latestDateLabel = null;
		$showAll = true;

		$lookup = app(BidReferenceLookupService::class);
		$manilaDirectoryUsers = [];
		try {
			$manilaDirectoryUsers = $lookup->getManilaAssignableUsersForSelect();
		} catch (\Throwable $e) {
			Log::warning('Manila directory users not loaded', ['error' => $e->getMessage()]);
		}

		$categoryOptions = [];
		try {
			$categoryOptions = $lookup->getCategoriesForSelect();
		} catch (\Throwable $e) {
			Log::warning('Category list not loaded', ['error' => $e->getMessage()]);
		}

		$pendingCount = 0;
		try {
			$pendingCount = TempBid::count();
		} catch (\Throwable $e) {
			Log::warning('Pending bid count not loaded', ['error' => $e->getMessage()]);
		}

		$reportsService = app(BidReportsService::class);
		$defaultReportRange = $reportsService->defaultReportRange();
		$reportFrom = $defaultReportRange['from'];
		$reportTo = $defaultReportRange['to'];
		$reportFromInput = trim((string) $request->query('report_from', ''));
		$reportToInput = trim((string) $request->query('report_to', ''));

		try {
			if ($reportFromInput !== '') {
				$reportFrom = \Illuminate\Support\Carbon::parse($reportFromInput, 'Asia/Manila')->startOfDay();
			}
			if ($reportToInput !== '') {
				$reportTo = \Illuminate\Support\Carbon::parse($reportToInput, 'Asia/Manila')->endOfDay();
			}
			if ($reportFrom->gt($reportTo)) {
				[$reportFrom, $reportTo] = [$reportTo->copy()->startOfDay(), $reportFrom->copy()->endOfDay()];
			}
		} catch (\Throwable $e) {
			$reportFrom = $defaultReportRange['from'];
			$reportTo = $defaultReportRange['to'];
		}

		$userActivityReport = [
			'rows' => [],
			'totals' => ['bids_added' => 0, 'urls_visited' => 0, 'bids_today' => 0],
			'from' => $reportFrom,
			'to' => $reportTo,
			'today' => now('Asia/Manila')->startOfDay(),
		];
		if ($manilaDirectoryUsers !== []) {
			try {
				$userActivityReport = $reportsService->userActivityReport($manilaDirectoryUsers, $reportFrom, $reportTo);
			} catch (\Throwable $e) {
				Log::warning('User activity report not loaded', ['error' => $e->getMessage()]);
			}
		}

		$activeTab = $request->query('tab', 'bids');
		if (!in_array($activeTab, ['bids', 'issues', 'noentities', 'nocategories', 'reports'], true)) {
			$activeTab = 'bids';
		}

		return view('bids.index', compact(
			'bids',
			'noEntityBids',
			'noCategoryBids',
			'issueCount',
			'scrapeLogs',
			'search',
			'filterDate',
			'filterUserIdRaw',
			'showAll',
			'latestDateLabel',
			'manilaDirectoryUsers',
			'categoryOptions',
			'bidListingRecentDays',
			'includeHistorical',
			'pendingCount',
			'userActivityReport',
			'reportFrom',
			'reportTo',
			'activeTab',
		));
	}

	public function store(Request $request, ScraperService $scraper, AIExtractor $ai, BidUrlManualEntryService $visitTracking)
	{
		// Allow more time/memory for heavy PDF pages to avoid timeouts during single-URL scrapes.
		// Allow enough wall-clock for AI + persistence (bulk extract can legitimately exceed 90s HTTP alone).
		@set_time_limit((int) max(600, $this->scrapeUrlMaxSeconds() + 240));
		@ini_set('max_execution_time', (string) max(600, $this->scrapeUrlMaxSeconds() + 240));
		@ini_set('memory_limit', '1024M');

		$validated = $request->validate([
			'URL' => ['required', 'url', 'max:2048', 'regex:/^https?:\\/\\//i'],
		], [
			'URL.required' => 'Please enter a link to a page that lists open bids.',
			'URL.url' => 'The link needs to be a valid URL (for example, https://example.gov/bids).',
			'URL.max' => 'The link is too long. Please paste a shorter, direct link to the bids page.',
			'URL.regex' => 'Only http:// or https:// links are supported.',
		]);

		$configuredBidUrl = $visitTracking->findConfiguredByUrl($validated['URL']);
		$visitStart = $this->beginScrapeVisit($configuredBidUrl, $visitTracking);

		try {
			$urlStartedAt = microtime(true);
			$result = $scraper->fetch($validated['URL']);
			if (!empty($result['blocked'])) {
				$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
				$this->logIssue(null, $validated['URL'], 'error', 'Blocked by site protection/firewall.' . $reason);
				return back()->withErrors([
					'URL' => 'The site blocked our request with site protection/firewall rules.' . $reason . ' Please try a different source URL, browser session cookie, or an approved API/feed for this site.'
				])->withInput();
			}
			if (!empty($result['no_open_bids'])) {
				$this->logIssue(null, $validated['URL'], 'warning', 'No open bids found on this page.');
				return back()->withErrors([
					'URL' => 'No open bids found on this page.'
				]);
			}
			if (empty($result['html']) && empty($result['pdf_bids']) && empty($result['bid_pages'])) {
				$this->logIssue(null, $validated['URL'], 'error', 'Could not read any content from this link.');
				return back()->withErrors([
					'URL' => 'We could not read any content from this link. Please confirm the page is publicly accessible and lists current bids.'
				])->withInput();
			}
			Log::info('SCRAPER DEBUG', [
				'url' => $validated['URL'],
				'pdf_links' => $result['pdf_bids'] ?? [],
				'text_length' => strlen($result['text'] ?? ''),
				'clicked_bid_pages' => count($result['bid_pages'] ?? []),
			]);

			Log::info('AI bid extract starting', [
				'url' => $validated['URL'],
				'pdf_text_chars' => strlen($result['pdf_text'] ?? ''),
				'listing_text_chars' => strlen($result['text'] ?? ''),
				'bid_pages' => count($result['bid_pages'] ?? []),
			]);
			$aiExtractStarted = microtime(true);
			$extracted = $ai->extract(
				$validated['URL'],
				$result['html'],
				$result['text'],
				$result['pdf_bids'] ?? [],
				$result['pdf_text'] ?? '',
				$result['bid_pages'] ?? []
			);
			Log::info('AI bid extract finished', [
				'url' => $validated['URL'],
				'elapsed_sec' => round(microtime(true) - $aiExtractStarted, 2),
				'bids_returned' => count($extracted['bids'] ?? []),
			]);

			$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $validated['URL']);

			$today = \Carbon\Carbon::today();
			$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
				if (!empty($bid['ENDDATE'])) {
					try {
						$end = \Carbon\Carbon::parse($bid['ENDDATE']);
						if ($end->lt($today)) {
							return false;
						}
					} catch (\Exception $e) {
						return false;
					}
				}
				return true;
			})->values();

			$saved = [];
			$duplicates = [];
			$nonBids = [];
			$possibleDuplicates = [];

			$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
			$rewrittenTitles = $this->maybeRewriteScrapeTitles($ai, $rawTitles, $urlStartedAt, $validated['URL'], null);
			$titleMap = $this->buildScrapeTitleMap($rawTitles, $rewrittenTitles, $validated['URL']);

			$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $validated['URL']);

			$duplicateMatcher = app(BidDuplicateMatcher::class);
			$runTracker = new BidDuplicateRunTracker();

			foreach ($filteredBids as $bidData) {
				$rawTitle = $bidData['TITLE'] ?? null;
				if (!$rawTitle) {
					continue;
				}

				$savedUrl = $this->resolveScrapedBidUrl($validated['URL'], $bidData, $result['bid_pages'] ?? [], $rawTitle);
				$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

				$dup = $this->evaluateScrapedBidDuplicate($bidData, $savedUrl, null, $duplicateMatcher, $runTracker);
				if ($dup !== null && $dup->shouldSkipSave()) {
					$duplicates[] = $rawTitle;
					continue;
				}
				if ($dup !== null && $dup->isPossibleDuplicate()) {
					$possibleDuplicates[] = $rawTitle;
				}

				$title = $titleMap[$rawTitle] ?? $rawTitle;
				$title = $this->applyCorporateTitlePrefix($title, $bidData['POSTING_ENTITY'] ?? 'uncertain');
				$identity = BidIdentity::fromScrapeExtract($bidData, $savedUrl, null, $title);

				$description = $bidData['DESCRIPTION'] ?? '';
				if (is_array($description)) {
					$description = $this->formatDescriptionArray($description);
				}
				$description = $this->stripNotProvidedLines($description);
				if (empty($description) && !empty($result['pdf_text'])) {
					$description = $result['pdf_text'];
				}
				if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? '')) {
					$description = $result['pdf_bids'][0]['PDF_LINK'];
				}

				if (!$this->looksLikeBid($title, $description, $validated['URL'], $endDate, $identity)) {
					$nonBids[] = $title;
					continue;
				}

				$bid = new TempBid();
				$bid->URL = $savedUrl;
				$bid->TITLE = $title;
				$bid->ENDDATE = $endDate;
				$bid->NAICSCODE = $this->normalizeNaicsCode(
					$bidData['NAICSCODE'] ?? null,
					$description,
					$title,
					$validated['URL']
				);
				$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
				$bid->EMAIL = $this->resolveBidContactEmail($bidData, $description);
				$bid->CREATED = now();
				$bid->LAST_MODIFIED = now();
				$bid->source_listing_url = $validated['URL'];
				$this->applyBidReferenceFieldsFromScrape($bid, $bidData, $title, $description, $validated['URL']);
				$this->applyScrapeCreatedBidDefaults($bid);
				$bid->save();

				$runTracker->remember($identity);
				$saved[] = $bid->id;
			}

			// 4) Response
			if (empty($saved) && !empty($duplicates)) {
				return back()->withErrors([
					'URL' => 'All bids already saved: ' . implode(', ', $duplicates)
				]);
			}

			if (empty($saved) && empty($duplicates) && empty($nonBids)) {
				return back()->withErrors([
					'URL' => 'No open bids found or extractable data from this URL.'
				]);
			}

			$msg = '';
			if ($saved) {
				$msg .= count($saved) . ' bid(s) queued for approval. Review them under Pending Approval. ';
			}
			if ($duplicates) {
				$msg .= count($duplicates) . ' duplicate bid(s) skipped.';
			}
			if ($possibleDuplicates) {
				$msg .= ' ' . count($possibleDuplicates) . ' possible duplicate(s) saved for review.';
			}
			if ($nonBids) {
				$msg .= ' No Bids Found. Please check the URL.';
			}

			return redirect()->route('bids.index')->with('success', trim($msg));
		} catch (\Throwable $e) {
			Log::error('Scrape failed', [
				'url' => $validated['URL'],
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			$this->logIssue(null, $validated['URL'], 'error', $this->friendlyExceptionMessage($e));

			return back()->withErrors([
				'URL' => $this->friendlyExceptionMessage($e)
			])->withInput();
		} finally {
			$this->finishScrapeVisit($configuredBidUrl, $visitStart, $visitTracking);
		}
	}

	public function scrapeUrlStream(Request $request, ScraperService $scraper, AIExtractor $ai, BidUrlManualEntryService $visitTracking)
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '1G');

		$url = trim((string) $request->query('url', ''));

		$assignUserId = $this->resolveOptionalManilaAssignUserId($request->query('assign_user_id'));

		if (session()->isStarted()) {
			session()->save();
		}

		return new StreamedResponse(function () use ($url, $scraper, $ai, $assignUserId, $visitTracking) {
			$send = function ($data) {
				echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
				if (ob_get_level()) ob_flush();
				flush();
			};

			if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\\/\\//i', $url)) {
				$send(['type' => 'error', 'message' => 'Please enter a valid http/https URL.']);
				$send(['type' => 'complete', 'saved' => 0, 'duplicates' => 0]);
				return;
			}

			$configuredBidUrl = $visitTracking->findConfiguredByUrl($url);
			$visitStart = $this->beginScrapeVisit($configuredBidUrl, $visitTracking);

			try {
				$urlStartedAt = microtime(true);
				$send(['type' => 'status', 'step' => 'Fetching page...']);
				$result = $scraper->fetch($url);

				if (!empty($result['blocked'])) {
					$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
					$this->logIssue(null, $url, 'error', 'Blocked by site protection/firewall.' . $reason);
					$send(['type' => 'error', 'message' => 'Blocked by site protection/firewall.' . $reason]);
					$send(['type' => 'complete', 'saved' => 0, 'duplicates' => 0]);
					return;
				}
				if (!empty($result['no_open_bids'])) {
					$this->logIssue(null, $url, 'warning', 'No open bids found on this page.');
					$send(['type' => 'error', 'message' => 'No open bids found on this page.']);
					$send(['type' => 'complete', 'saved' => 0, 'duplicates' => 0]);
					return;
				}
				if (empty($result['html']) && empty($result['pdf_bids']) && empty($result['bid_pages'])) {
					$this->logIssue(null, $url, 'error', 'Could not read any content from this link.');
					$send(['type' => 'error', 'message' => 'Could not read any content. Please confirm the page is publicly accessible.']);
					$send(['type' => 'complete', 'saved' => 0, 'duplicates' => 0]);
					return;
				}

				$listChars = strlen($result['text'] ?? '');
				$pdfC = strlen($result['pdf_text'] ?? '');
				$bidPg = count($result['bid_pages'] ?? []);
				$heavyAiPayloadEstSingle = $listChars >= 35000 || $pdfC >= 28000 || $bidPg >= 3;
				$send(['type' => 'status', 'step' => $this->sseStatusStepAiBidExtract($listChars, $pdfC, $bidPg)]);
				Log::info('AI bid extract starting', [
					'url' => $url,
					'pdf_text_chars' => $pdfC,
					'listing_text_chars' => $listChars,
					'bid_pages' => $bidPg,
					'heavy_ai_payload_estimate' => $heavyAiPayloadEstSingle,
					'busy_listing_tables_ai_estimate' => !$heavyAiPayloadEstSingle && $listChars >= 4000,
				]);
				$aiExtractStarted = microtime(true);
				$extracted = $ai->extract(
					$url,
					$result['html'],
					$result['text'],
					$result['pdf_bids'] ?? [],
					$result['pdf_text'] ?? '',
					$result['bid_pages'] ?? [],
					[
						'openai_heartbeat' => function (int $elapsedSec) use ($send): void {
							$send([
								'type' => 'status',
								'step' => 'OpenAI extract still running (~'
									. $elapsedSec . 's). Large JSON replies can exceed several minutes — watch “Elapsed this step”.',
							]);
						},
					]
				);
				Log::info('AI bid extract finished', [
					'url' => $url,
					'elapsed_sec' => round(microtime(true) - $aiExtractStarted, 2),
					'bids_returned' => count($extracted['bids'] ?? []),
				]);

				$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $url);

				$today = \Carbon\Carbon::today();
				$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
					if (!empty($bid['ENDDATE'])) {
						try { return !\Carbon\Carbon::parse($bid['ENDDATE'])->lt($today); } catch (\Exception $e) { return false; }
					}
					return true;
				})->values();

				$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
				$rewrittenTitles = $this->maybeRewriteScrapeTitles($ai, $rawTitles, $urlStartedAt, $url, function (string $ev, array $ctx) use ($send) {
					if ($ev === 'start') {
						$total = (int) ($ctx['count'] ?? 0);
						$chunks = (int) ($ctx['chunks'] ?? 1);
						$batchHint = $chunks > 1 ? " ({$chunks} OpenAI batches)" : '';
						$send([
							'type' => 'status',
							'step' => 'Rewriting ' . $total . ' title(s)' . $batchHint . '… SSE pauses until each batch returns (often 15–120s total). Watch “Elapsed this step”.',
						]);
					} elseif ($ev === 'chunk') {
						$b = (int) ($ctx['chunk'] ?? 2);
						$t = (int) ($ctx['total_chunks'] ?? 2);
						$send(['type' => 'status', 'step' => "Rewriting titles — batch {$b}/{$t}… waiting on OpenAI."]);
					} elseif ($ev === 'skip_time') {
						$send(['type' => 'status', 'step' => 'Using extracted titles (rewrite skipped — near per-URL time limit).']);
					} elseif ($ev === 'skip_many') {
						$n = $ctx['count'] ?? 0;
						$send(['type' => 'status', 'step' => "Using extracted titles (rewrite skipped — {$n} opportunities)."]);
					}
				});
				$titleMap = $this->buildScrapeTitleMap($rawTitles, $rewrittenTitles, $url);

				$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $url);

				$send(['type' => 'status', 'step' => 'Saving bids...']);
				$savedCount = 0;
				$duplicateCount = 0;
				$possibleDuplicateCount = 0;
				$rejectedCount = 0;
				$duplicateMatcher = app(BidDuplicateMatcher::class);
				$runTracker = new BidDuplicateRunTracker();

				foreach ($filteredBids as $bidData) {
					$rawTitle = $bidData['TITLE'] ?? null;
					if (!$rawTitle) continue;

					$savedUrl = $this->resolveScrapedBidUrl($url, $bidData, $result['bid_pages'] ?? [], $rawTitle);
					$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

					$dup = $this->evaluateScrapedBidDuplicate($bidData, $savedUrl, null, $duplicateMatcher, $runTracker);
					if ($dup !== null && $dup->shouldSkipSave()) { $duplicateCount++; continue; }
					if ($dup !== null && $dup->isPossibleDuplicate()) { $possibleDuplicateCount++; }

					$title = $titleMap[$rawTitle] ?? $rawTitle;
					$title = $this->applyCorporateTitlePrefix($title, $bidData['POSTING_ENTITY'] ?? 'uncertain');
					$identity = BidIdentity::fromScrapeExtract($bidData, $savedUrl, null, $title);

					$description = $bidData['DESCRIPTION'] ?? '';
					if (is_array($description)) $description = $this->formatDescriptionArray($description);
					$description = $this->stripNotProvidedLines($description);
					if (empty($description) && !empty($result['pdf_text'])) $description = $result['pdf_text'];
					if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? '')) $description = $result['pdf_bids'][0]['PDF_LINK'];

					if (!$this->looksLikeBid($title, $description, $url, $endDate, $identity)) { $rejectedCount++; continue; }

					$bid = new TempBid();
					$bid->URL = $savedUrl;
					$bid->TITLE = $title;
					$bid->ENDDATE = $endDate;
					$bid->NAICSCODE = $this->normalizeNaicsCode($bidData['NAICSCODE'] ?? null, $description, $title, $url);
					$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
					$bid->EMAIL = $this->resolveBidContactEmail($bidData, $description);
					$bid->CREATED = now();
					$bid->LAST_MODIFIED = now();
					$bid->source_listing_url = $url;
					$this->applyBidReferenceFieldsFromScrape($bid, $bidData, $title, $description, $url);
					$this->applyScrapeAssignUserId($bid, $assignUserId);
					$this->applyScrapeCreatedBidDefaults($bid);
					$bid->save();
					$runTracker->remember($identity);
					$savedCount++;

					$send(['type' => 'saved_bid', 'title' => $title]);
				}

				$send(['type' => 'complete', 'saved' => $savedCount, 'duplicates' => $duplicateCount, 'possible_duplicates' => $possibleDuplicateCount, 'rejected' => $rejectedCount]);
			} catch (\Throwable $e) {
				Log::error('Single URL scrape failed', ['url' => $url, 'error' => $e->getMessage()]);
				$this->logIssue(null, $url, 'error', $this->friendlyExceptionMessage($e));
				$send(['type' => 'error', 'message' => $this->friendlyExceptionMessage($e)]);
				$send(['type' => 'complete', 'saved' => 0, 'duplicates' => 0]);
			} finally {
				$this->finishScrapeVisit($configuredBidUrl, $visitStart, $visitTracking);
			}
		}, 200, [
			'Content-Type' => 'text/event-stream',
			'Cache-Control' => 'no-cache',
			'Connection' => 'keep-alive',
			'X-Accel-Buffering' => 'no',
		]);
	}

	public function show(Bid $bid)
	{
		return view('bids.show', compact('bid'));
	}

	/** JSON for detail modals (e.g. similar bids on Pending page). */
	public function showJson(Bid $bid, BidReferenceLookupService $lookup)
	{
		return response()->json(BidDetailPayload::fromLive($bid, $lookup));
	}

	/** JSON: all stored columns for a live bid (Reports drill-down raw record). */
	public function showRecordJson(Bid $bid)
	{
		return response()->json(BidRecordPayload::fromLive($bid));
	}

	public function scrapeAll(Request $request, ScraperService $scraper, AIExtractor $ai, BidUrlManualEntryService $visitTracking)
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '1G');

		$assignUserId = $this->resolveOptionalManilaAssignUserId($request->input('assign_user_id'));

		$today = \Carbon\Carbon::today();
		$bidUrls = BidUrl::all();
		$totalSaved = 0;
		$totalDuplicates = 0;
		$totalSkipped = 0;
		$scrapeIssues = [];
		$failedUrls = [];

		foreach ($bidUrls as $bidUrl) {
			$visitStart = null;
			try {
				$url = trim((string) ($bidUrl->URL ?? $bidUrl->url ?? ''));
				if ($url === '') {
					$this->logIssue($bidUrl->id, "Record ID {$bidUrl->id}", 'warning', 'Missing URL, skipped.');
					$scrapeIssues[] = "Record ID {$bidUrl->id} - missing URL, skipped.";
					continue;
				}
				if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\\/\\//i', $url)) {
					$this->logIssue($bidUrl->id, $url, 'warning', 'Not a valid http/https link, skipped.');
					$scrapeIssues[] = "{$url} - not a valid http/https link, skipped.";
					continue;
				}

				if ($this->shouldSkipBidUrlScrapedToday($bidUrl)) {
					$totalSkipped++;
					continue;
				}

				$visitStart = $this->beginScrapeVisit($bidUrl, $visitTracking);
				$urlStartedAt = microtime(true);

				// 1) Fetch data for each bid URL (batch: skip interactive scan, tighter PDF/detail caps)
				$result = $scraper->fetch($url, $bidUrl->username ?? null, $bidUrl->password ?? null, ['batch' => true]);
				if (!empty($result['blocked'])) {
					$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
					$this->logIssue($bidUrl->id, $url, 'error', 'Blocked by site protection/firewall.' . $reason);
					$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, 'Blocked by site protection/firewall.' . $reason);
					$scrapeIssues[] = "{$url} - blocked by site protection/firewall." . $reason;
					continue;
				}
				if (!empty($result['no_open_bids'])) {
					$this->logIssue($bidUrl->id, $url, 'warning', 'No open bids listed.');
					$scrapeIssues[] = "{$url} - no open bids listed.";
					continue;
				}
				if (empty($result['html']) && empty($result['pdf_bids']) && empty($result['bid_pages'])) {
					$this->logIssue($bidUrl->id, $url, 'error', 'Page content could not be read.');
					$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, 'Page content could not be read.');
					$scrapeIssues[] = "{$url} - page content could not be read.";
					continue;
				}
				Log::info('SCRAPER DEBUG scrapeAll', [
					'url' => $url,
					'pdf_links' => $result['pdf_bids'] ?? [],
					'text_length' => strlen($result['text'] ?? ''),
					'clicked_bid_pages' => count($result['bid_pages'] ?? []),
				]);

				Log::info('AI bid extract starting', [
					'url' => $url,
					'batch_scrape_all' => true,
					'bulk_ai_payload' => true,
					'pdf_text_chars' => strlen($result['pdf_text'] ?? ''),
					'listing_text_chars' => strlen($result['text'] ?? ''),
					'bid_pages' => count($result['bid_pages'] ?? []),
				]);
				$aiExtractStarted = microtime(true);
				$extracted = $ai->extract(
					$url,
					$result['html'],
					$result['text'],
					$result['pdf_bids'] ?? [],
					$result['pdf_text'] ?? '',
					$result['bid_pages'] ?? [],
					['bulk_mode' => true]
				);
				Log::info('AI bid extract finished', [
					'url' => $url,
					'batch_scrape_all' => true,
					'elapsed_sec' => round(microtime(true) - $aiExtractStarted, 2),
					'bids_returned' => count($extracted['bids'] ?? []),
				]);

				$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $url);

				$today = \Carbon\Carbon::today();
				$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
					if (!empty($bid['ENDDATE'])) {
						try {
							$end = \Carbon\Carbon::parse($bid['ENDDATE']);
							if ($end->lt($today)) {
								return false;
							}
						} catch (\Exception $e) {
							return false;
						}
					}
					return true;
				})->values();

				$savedThisUrl = 0;
				$duplicatesThisUrl = 0;
				$nonBidsThisUrl = 0;
				$possibleDuplicatesThisUrl = 0;

				$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
				$rewrittenTitles = $this->maybeRewriteScrapeTitles($ai, $rawTitles, $urlStartedAt, $url, null);
				$titleMap = $this->buildScrapeTitleMap($rawTitles, $rewrittenTitles, $url);

				$this->guardUrlBudget($urlStartedAt, $this->scrapeUrlMaxSeconds(), $url);

				$duplicateMatcher = app(BidDuplicateMatcher::class);
				$runTracker = new BidDuplicateRunTracker();

				foreach ($filteredBids as $bidData) {
					$rawTitle = $bidData['TITLE'] ?? null;
					if (!$rawTitle) {
						continue;
					}

					$savedUrl = $this->resolveScrapedBidUrl($url, $bidData, $result['bid_pages'] ?? [], $rawTitle);
					$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

					$dup = $this->evaluateScrapedBidDuplicate($bidData, $savedUrl, (int) $bidUrl->id, $duplicateMatcher, $runTracker);
					if ($dup !== null && $dup->shouldSkipSave()) {
						$duplicatesThisUrl++;
						continue;
					}
					if ($dup !== null && $dup->isPossibleDuplicate()) {
						$possibleDuplicatesThisUrl++;
					}

					$title = $titleMap[$rawTitle] ?? $rawTitle;
					$title = $this->applyCorporateTitlePrefix($title, $bidData['POSTING_ENTITY'] ?? 'uncertain');
					$identity = BidIdentity::fromScrapeExtract($bidData, $savedUrl, (int) $bidUrl->id, $title);

					$description = $bidData['DESCRIPTION'] ?? '';
					if (is_array($description)) {
						$description = $this->formatDescriptionArray($description);
					}
					$description = $this->stripNotProvidedLines($description);
					if (empty($description) && !empty($result['pdf_text'])) {
						$description = $result['pdf_text'];
					}
					if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? '')) {
						$description = $result['pdf_bids'][0]['PDF_LINK'];
					}

					if (!$this->looksLikeBid($title, $description, $url, $endDate, $identity)) {
						$nonBidsThisUrl++;
						continue;
					}

					$bid = new TempBid();
					$bid->URL = $savedUrl;
					$bid->TITLE = $title;
					$bid->ENDDATE = $endDate;
					$bid->NAICSCODE = $this->normalizeNaicsCode(
						$bidData['NAICSCODE'] ?? null,
						$description,
						$title,
						$url
					);
					$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
					$bid->EMAIL = $this->resolveBidContactEmail($bidData, $description);
					$bid->CREATED = now();
					$bid->LAST_MODIFIED = now();
					$bid->BID_URL_ID = $bidUrl->id;
					$bid->source_listing_url = $url;
					$bid->bid_url_name = $bidUrl->name ?? null;
					$this->applyBidReferenceFieldsFromScrape(
						$bid,
						$bidData,
						$title,
						$description,
						$url,
						$bidUrl->name ?? null
					);
					$this->applyScrapeAssignUserId($bid, $assignUserId);
					$this->applyScrapeCreatedBidDefaults($bid);
					$bid->save();
					$runTracker->remember($identity);

					$savedThisUrl++;
				}

				$totalSaved += $savedThisUrl;
				$totalDuplicates += $duplicatesThisUrl;

				Log::info('Scrape summary for ' . $url, [
					'saved' => $savedThisUrl,
					'duplicates' => $duplicatesThisUrl,
					'possible_duplicates' => $possibleDuplicatesThisUrl,
					'non_bids' => $nonBidsThisUrl,
				]);

				if ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl === 0) {
					$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found.');
					$scrapeIssues[] = "{$url} - no bids found.";
				} elseif ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl > 0) {
					$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found. Please check the URL.');
					$scrapeIssues[] = "{$url} - No Bids found. Please check the URL.";
				}
			} catch (\Throwable $e) {
				Log::error('Scrape failed for URL: ' . $url, [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);
				$this->logIssue($bidUrl->id ?? null, $url ?? 'unknown', 'error', $this->friendlyExceptionMessage($e));
				if (isset($bidUrl) && $bidUrl instanceof BidUrl) {
					$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, $this->friendlyExceptionMessage($e));
				}
				$failedUrls[] = $url;
				$scrapeIssues[] = "{$url} - " . $this->friendlyExceptionMessage($e);
			} finally {
				$this->finishScrapeVisit($bidUrl, $visitStart, $visitTracking);
			}
		}

		// 4) Build final response
		$msg = "{$totalSaved} new bid(s) saved.";
		if ($totalDuplicates > 0) {
			$msg .= " Skipped {$totalDuplicates} duplicate bid(s).";
		}
		if ($totalSkipped > 0) {
			$msg .= " {$totalSkipped} URL(s) already scraped today.";
		}

		$redirect = redirect()->route('bids.index')->with('scrape_issues', $scrapeIssues);

		// Always surface failures to the user instead of silently succeeding.
		if (!empty($failedUrls) || ($totalSaved === 0 && $totalDuplicates === 0)) {
			$errorMsg = [];
			if (!empty($failedUrls)) {
				$errorMsg[] = "Failed URLs: " . implode(', ', $failedUrls);
			}
			if ($totalSaved === 0 && $totalDuplicates === 0) {
				$errorMsg[] = 'No bids were scraped from the configured URLs.';
			}

			$redirect = $redirect->withErrors([
				'scrape_all' => implode(' ', $errorMsg)
			]);
		}

		if ($totalSaved > 0 || $totalDuplicates > 0) {
			$redirect = $redirect->with('success', trim($msg));
		}

		return $redirect;
	}

	public function scrapeStream(Request $request, ScraperService $scraper, AIExtractor $ai, BidUrlManualEntryService $visitTracking)
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '1G');

		if (session()->isStarted()) {
			session()->save();
		}

		$assignUserId = $this->resolveOptionalManilaAssignUserId($request->query('assign_user_id'));
		$singlePerUrlFiveMin = $request->boolean('single_url_max');
		$urlMaxBudget = $singlePerUrlFiveMin ? 300 : $this->scrapeUrlMaxSeconds();

		return new StreamedResponse(function () use ($scraper, $ai, $assignUserId, $urlMaxBudget, $singlePerUrlFiveMin, $visitTracking) {
			// "Latest run wins": claim ownership so an older run (e.g. user refreshed the page and re-clicked
			// Scrape All) self-terminates instead of running to completion in the background and overlapping.
			$runKey = 'scrape:stream:active_run';
			$runId = bin2hex(random_bytes(8));
			Cache::put($runKey, $runId, now()->addHours(6));

			$shouldStop = function () use ($runKey, $runId): bool {
				if (function_exists('connection_aborted') && connection_aborted() === 1) {
					return true;
				}

				return Cache::get($runKey) !== $runId;
			};

			$send = function ($data) {
				echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
				if (ob_get_level())
					ob_flush();
				flush();
			};

			$bidUrls = BidUrl::all();
			$total = $bidUrls->count();
			$totalSaved = 0;
			$totalDuplicates = 0;
			$totalPossibleDuplicates = 0;
			$totalRejected = 0;
			$totalSkipped = 0;
			$totalIssues = 0;

			if ($singlePerUrlFiveMin) {
				Log::info('Bulk scrape-stream: single per-URL max 300s enforced (SCRAPER_URL_MAX_SECONDS ignored per row).');
			}

			$send([
				'type' => 'start',
				'total' => $total,
				'url_max_budget_sec' => $urlMaxBudget,
				'single_per_url_cap' => $singlePerUrlFiveMin,
			]);

			foreach ($bidUrls as $idx => $bidUrl) {
				if ($shouldStop()) {
					Log::info('Bulk scrape-stream stopped — superseded by a newer run or client disconnected.', [
						'run_id' => $runId,
						'stopped_before_index' => $idx + 1,
						'total' => $total,
					]);
					$send(['type' => 'stopped', 'reason' => 'superseded']);

					return;
				}
				// Extend ownership for long runs (we are confirmed owner from the check above).
				Cache::put($runKey, $runId, now()->addHours(6));

				$url = trim((string) ($bidUrl->URL ?? $bidUrl->url ?? ''));
				$visitStart = null;

				try {
				if ($url === '') {
					$this->logIssue($bidUrl->id, "Record ID {$bidUrl->id}", 'warning', 'Missing URL, skipped.');
					$totalIssues++;
					$send(['type' => 'skip', 'index' => $idx + 1, 'url' => "(empty)", 'reason' => 'Missing URL']);
					continue;
				}

				if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\\/\\//i', $url)) {
					$this->logIssue($bidUrl->id, $url, 'warning', 'Not a valid http/https link, skipped.');
					$totalIssues++;
					$send(['type' => 'skip', 'index' => $idx + 1, 'url' => $url, 'reason' => 'Invalid URL']);
					continue;
				}

				if ($this->shouldSkipBidUrlScrapedToday($bidUrl)) {
					$totalSkipped++;
					$send(['type' => 'skip', 'index' => $idx + 1, 'url' => $url, 'reason' => 'Already scraped today']);
					continue;
				}

				$send(['type' => 'processing', 'index' => $idx + 1, 'url' => $url]);

				$visitStart = $this->beginScrapeVisit($bidUrl, $visitTracking);

				$urlStartedAt = microtime(true);

					$send(['type' => 'status', 'index' => $idx + 1, 'step' => 'Fetching page...']);
					$result = $scraper->fetch($url, $bidUrl->username ?? null, $bidUrl->password ?? null, [
						'batch' => true,
						'url_max_seconds' => $urlMaxBudget,
						'should_abort' => $shouldStop,
						'on_progress' => function (string $message) use ($send, $idx, $shouldStop) {
							if ($shouldStop()) {
								throw new ScrapeAbortedException('Scrape aborted (superseded or disconnected)');
							}
							$send(['type' => 'status', 'index' => $idx + 1, 'step' => $message]);
						},
					]);

					$this->guardUrlBudget($urlStartedAt, $urlMaxBudget, $url);

					if (!empty($result['blocked'])) {
						$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
						$this->logIssue($bidUrl->id, $url, 'error', 'Blocked by site protection/firewall.' . $reason);
						$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, 'Blocked by site protection/firewall.' . $reason);
						$totalIssues++;
						$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => 'Blocked by site protection']);
						continue;
					}
					if (!empty($result['no_open_bids'])) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No open bids listed.');
						$totalIssues++;
						$send(['type' => 'done_url', 'index' => $idx + 1, 'url' => $url, 'saved' => 0, 'duplicates' => 0, 'message' => 'No open bids']);
						continue;
					}
					if (empty($result['html']) && empty($result['pdf_bids']) && empty($result['bid_pages'])) {
						$this->logIssue($bidUrl->id, $url, 'error', 'Page content could not be read.');
						$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, 'Page content could not be read.');
						$totalIssues++;
						$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => 'Could not read content']);
						continue;
					}

					// Extra padding avoids some proxies buffering the stream until bytes arrive during the blocking OpenAI call.
					echo ':' . str_repeat(' ', 2048) . "\n\n";
					if (ob_get_level())
						ob_flush();
					flush();

					$this->guardUrlBudget($urlStartedAt, $urlMaxBudget, $url);

					$listingCharsPreAi = strlen($result['text'] ?? '');
					$pdfCharsPreAi = strlen($result['pdf_text'] ?? '');
					$bidPageCountPreAi = count($result['bid_pages'] ?? []);
					$heavyAiPayload = $listingCharsPreAi >= 35000 || $pdfCharsPreAi >= 28000 || $bidPageCountPreAi >= 3;

					$send(['type' => 'status', 'index' => $idx + 1, 'step' => $this->sseStatusStepAiBidExtract($listingCharsPreAi, $pdfCharsPreAi, $bidPageCountPreAi)]);
					Log::info('AI bid extract starting', [
						'url' => $url,
						'index' => $idx + 1,
						'bulk_ai_payload' => true,
						'pdf_text_chars' => $pdfCharsPreAi,
						'listing_text_chars' => $listingCharsPreAi,
						'bid_pages' => $bidPageCountPreAi,
						'heavy_ai_payload_estimate' => $heavyAiPayload,
						'busy_listing_tables_ai_estimate' => !$heavyAiPayload && $listingCharsPreAi >= 4000,
					]);
					$aiExtractStarted = microtime(true);
					$remainingAiSec = $this->remainingUrlBudgetSeconds($urlStartedAt, $urlMaxBudget);
					// Bound a single (possibly stalled) OpenAI extract so it cannot eat the whole per-URL budget.
					$aiExtractCap = max(30, (int) config('scraper.ai_bulk_extract_max_seconds', 150));
					$remainingAiSec = $remainingAiSec > 0 ? min($remainingAiSec, $aiExtractCap) : $aiExtractCap;
					$extracted = $ai->extract(
						$url,
						$result['html'],
						$result['text'],
						$result['pdf_bids'] ?? [],
						$result['pdf_text'] ?? '',
						$result['bid_pages'] ?? [],
						[
							'bulk_mode' => true,
							'max_wall_clock_sec' => $remainingAiSec,
							'openai_heartbeat' => function (int $elapsedSec) use ($send, $idx): void {
								$send([
									'type' => 'status',
									'index' => $idx + 1,
									'step' => 'OpenAI extract still running (~'
										. $elapsedSec . 's). Large JSON replies can exceed several minutes — watch “Elapsed this step”.',
								]);
							},
						]
					);
					$aiBidCount = count($extracted['bids'] ?? []);
					Log::info('AI bid extract finished', [
						'url' => $url,
						'index' => $idx + 1,
						'elapsed_sec' => round(microtime(true) - $aiExtractStarted, 2),
						'bids_returned' => $aiBidCount,
					]);

					$this->guardUrlBudget($urlStartedAt, $urlMaxBudget, $url);

					$today = \Carbon\Carbon::today();
					$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
						if (!empty($bid['ENDDATE'])) {
							try {
								return !\Carbon\Carbon::parse($bid['ENDDATE'])->lt($today);
							} catch (\Exception $e) {
								return false;
							}
						}
						return true;
					})->values();

					$savedThisUrl = 0;
					$duplicatesThisUrl = 0;
					$nonBidsThisUrl = 0;
					$possibleDuplicatesThisUrl = 0;

					$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
					$rewrittenTitles = $this->maybeRewriteScrapeTitles($ai, $rawTitles, $urlStartedAt, $url, function (string $ev, array $ctx) use ($send, $idx) {
						if ($ev === 'start') {
							$total = (int) ($ctx['count'] ?? 0);
							$chunks = (int) ($ctx['chunks'] ?? 1);
							$batchHint = $chunks > 1 ? " ({$chunks} OpenAI batches)" : '';
							$send([
								'type' => 'status',
								'index' => $idx + 1,
								'step' => 'Rewriting ' . $total . ' title(s)' . $batchHint . '… SSE pauses until each batch returns (often 15–120s total). Watch “Elapsed this step”.',
							]);
						} elseif ($ev === 'chunk') {
							$b = (int) ($ctx['chunk'] ?? 2);
							$t = (int) ($ctx['total_chunks'] ?? 2);
							$send([
								'type' => 'status',
								'index' => $idx + 1,
								'step' => "Rewriting titles — batch {$b}/{$t}… waiting on OpenAI.",
							]);
						} elseif ($ev === 'skip_time') {
							$send(['type' => 'status', 'index' => $idx + 1, 'step' => 'Using extracted titles (rewrite skipped — near per-URL time limit).']);
						} elseif ($ev === 'skip_many') {
							$n = $ctx['count'] ?? 0;
							$send(['type' => 'status', 'index' => $idx + 1, 'step' => "Using extracted titles (rewrite skipped — {$n} opportunities)."]);
						}
					}, $urlMaxBudget);
					$titleMap = $this->buildScrapeTitleMap($rawTitles, $rewrittenTitles, $url);

					$this->guardUrlBudget($urlStartedAt, $urlMaxBudget, $url);
					$send(['type' => 'status', 'index' => $idx + 1, 'step' => 'Saving bids...']);

					$duplicateMatcher = app(BidDuplicateMatcher::class);
					$runTracker = new BidDuplicateRunTracker();

					foreach ($filteredBids as $bidData) {
						$this->guardUrlBudget($urlStartedAt, $urlMaxBudget, $url);
						$rawTitle = $bidData['TITLE'] ?? null;
						if (!$rawTitle)
							continue;

						$savedUrl = $this->resolveScrapedBidUrl($url, $bidData, $result['bid_pages'] ?? [], $rawTitle);
						$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

						$dup = $this->evaluateScrapedBidDuplicate($bidData, $savedUrl, (int) $bidUrl->id, $duplicateMatcher, $runTracker);
						if ($dup !== null && $dup->shouldSkipSave()) {
							$duplicatesThisUrl++;
							continue;
						}
						if ($dup !== null && $dup->isPossibleDuplicate()) {
							$possibleDuplicatesThisUrl++;
						}

						$title = $titleMap[$rawTitle] ?? $rawTitle;
						$title = $this->applyCorporateTitlePrefix($title, $bidData['POSTING_ENTITY'] ?? 'uncertain');
						$identity = BidIdentity::fromScrapeExtract($bidData, $savedUrl, (int) $bidUrl->id, $title);

						$description = $bidData['DESCRIPTION'] ?? '';
						if (is_array($description))
							$description = $this->formatDescriptionArray($description);
						$description = $this->stripNotProvidedLines($description);
						if (empty($description) && !empty($result['pdf_text']))
							$description = $result['pdf_text'];
						if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? ''))
							$description = $result['pdf_bids'][0]['PDF_LINK'];

						if (!$this->looksLikeBid($title, $description, $url, $endDate, $identity)) {
							Log::info('Scraped bid rejected (looksLikeBid)', [
								'url' => $url,
								'title' => $title,
								'has_end_date' => $endDate !== null && $endDate !== '',
								'has_tier_a_key' => $identity->hasTierAKey(),
							]);
							$nonBidsThisUrl++;
							continue;
						}

						$bid = new TempBid();
						$bid->URL = $savedUrl;
						$bid->TITLE = $title;
						$bid->ENDDATE = $endDate;
						$bid->NAICSCODE = $this->normalizeNaicsCode($bidData['NAICSCODE'] ?? null, $description, $title, $url);
						$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
						$bid->EMAIL = $this->resolveBidContactEmail($bidData, $description);
						$bid->CREATED = now();
						$bid->LAST_MODIFIED = now();
						$bid->BID_URL_ID = $bidUrl->id;
						$bid->source_listing_url = $url;
						$bid->bid_url_name = $bidUrl->name ?? null;
						$this->applyBidReferenceFieldsFromScrape(
							$bid,
							$bidData,
							$title,
							$description,
							$url,
							$bidUrl->name ?? null
						);
						$this->applyScrapeAssignUserId($bid, $assignUserId);
						$this->applyScrapeCreatedBidDefaults($bid);
						$bid->save();
						$runTracker->remember($identity);
						$savedThisUrl++;
					}

					$totalSaved += $savedThisUrl;
					$totalDuplicates += $duplicatesThisUrl;
					$totalPossibleDuplicates += $possibleDuplicatesThisUrl;
					$totalRejected += $nonBidsThisUrl;

					$expiredFiltered = max(0, $aiBidCount - $filteredBids->count());
					Log::info('Scrape URL summary', [
						'url' => $url,
						'ai_returned' => $aiBidCount,
						'after_date_filter' => $filteredBids->count(),
						'expired_filtered' => $expiredFiltered,
						'saved' => $savedThisUrl,
						'duplicates' => $duplicatesThisUrl,
						'possible_duplicates' => $possibleDuplicatesThisUrl,
						'rejected_non_bid' => $nonBidsThisUrl,
					]);

					if ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl === 0) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found.');
						$totalIssues++;
					} elseif ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl > 0) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found. Please check the URL.');
						$totalIssues++;
					}

					$doneMessage = null;
					if ($savedThisUrl === 0 && $duplicatesThisUrl > 0) {
						$doneMessage = $duplicatesThisUrl . ' already pending or live — check Pending Approval';
					} elseif ($savedThisUrl === 0 && $nonBidsThisUrl > 0) {
						$doneMessage = 'missing end date or weak bid signals';
					} elseif ($savedThisUrl === 0 && $aiBidCount === 0) {
						$doneMessage = 'AI returned no bids';
					} elseif ($savedThisUrl === 0 && $expiredFiltered > 0 && $filteredBids->count() === 0) {
						$doneMessage = $expiredFiltered . ' expired (filtered out)';
					}

					$send([
						'type' => 'done_url',
						'index' => $idx + 1,
						'url' => $url,
						'saved' => $savedThisUrl,
						'duplicates' => $duplicatesThisUrl,
						'possible_duplicates' => $possibleDuplicatesThisUrl,
						'rejected' => $nonBidsThisUrl,
						'message' => $doneMessage,
					]);

				} catch (ScrapeAbortedException $e) {
					Log::info('Bulk scrape-stream fetch aborted', ['url' => $url, 'run_id' => $runId]);
					$send(['type' => 'stopped', 'reason' => 'superseded']);

					return;
				} catch (\Throwable $e) {
					if ($this->isUrlBudgetTimeout($e)) {
						$msg = $this->friendlyExceptionMessage($e);
						Log::warning('Scrape skipped — per-URL time limit', ['url' => $url, 'budget_sec' => $urlMaxBudget, 'error' => $e->getMessage()]);
						$this->logIssue($bidUrl->id, $url, 'warning', $msg);
						$totalIssues++;
						$send(['type' => 'skip', 'index' => $idx + 1, 'url' => $url, 'reason' => $msg]);
						continue;
					}
					Log::error('Scrape failed for URL: ' . $url, ['error' => $e->getMessage()]);
					$this->logIssue($bidUrl->id, $url, 'error', $this->friendlyExceptionMessage($e));
					$this->maybeQuarantineBidUrlAfterScrapeFailure($bidUrl, $this->friendlyExceptionMessage($e));
					$totalIssues++;
					$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => $this->friendlyExceptionMessage($e)]);
				} finally {
					$this->finishScrapeVisit($bidUrl, $visitStart, $visitTracking);
				}
			}

			if (Cache::get($runKey) === $runId) {
				Cache::forget($runKey);
			}

			$send([
				'type' => 'complete',
				'total_saved' => $totalSaved,
				'total_duplicates' => $totalDuplicates,
				'total_possible_duplicates' => $totalPossibleDuplicates,
				'total_rejected' => $totalRejected,
				'total_skipped' => $totalSkipped,
				'total_issues' => $totalIssues,
			]);
		}, 200, [
			'Content-Type' => 'text/event-stream',
			'Cache-Control' => 'no-cache',
			'Connection' => 'keep-alive',
			'X-Accel-Buffering' => 'no',
		]);
	}

	private function logIssue(?int $bidUrlId, string $url, string $level, string $message): void
	{
		ScrapeLog::create([
			'bid_url_id' => $bidUrlId,
			'url' => $url,
			'level' => $level,
			'message' => $message,
			'created_at' => now(),
		]);
	}

	private function beginScrapeVisit(?BidUrl $bidUrl, BidUrlManualEntryService $visitTracking): ?\Illuminate\Support\Carbon
	{
		if (!$bidUrl) {
			return null;
		}

		return $visitTracking->beginConfigured($bidUrl);
	}

	private function finishScrapeVisit(?BidUrl $bidUrl, ?\Illuminate\Support\Carbon $visitStart, BidUrlManualEntryService $visitTracking): void
	{
		if (!$bidUrl || !$visitStart) {
			return;
		}

		try {
			$visitTracking->finishScrapeVisit((int) $bidUrl->id, $visitStart, auth()->id(), $bidUrl);
		} catch (\Throwable $e) {
			Log::warning('finishScrapeVisit failed (scrape result preserved)', [
				'bid_url_id' => $bidUrl->id,
				'error' => $e->getMessage(),
			]);
		}
	}

	private function maybeQuarantineBidUrlAfterScrapeFailure(BidUrl $bidUrl, string $message): void
	{
		if (!config('scraper.bid_url_quarantine_on_scrape_error', false)) {
			Log::info('Scrape failure recorded; BIDURL row kept', [
				'bid_url_id' => $bidUrl->getKey(),
				'user_id' => $this->readBidUrlAssigneeId($bidUrl),
				'url' => $this->readBidUrlColumn($bidUrl, 'url'),
				'reason' => $message,
			]);

			return;
		}

		$this->moveBidUrlToFailed($bidUrl, $message);
	}

	private function readBidUrlColumn(BidUrl $bidUrl, string $logicalColumn): mixed
	{
		$configKey = match (strtolower($logicalColumn)) {
			'id' => 'scraper.bid_url_id_column',
			'url' => 'scraper.bid_url_url_column',
			'name' => 'scraper.bid_url_name_column',
			'user_id' => 'scraper.bid_url_user_id_column',
			default => null,
		};
		$candidates = array_values(array_filter(array_unique([
			$configKey !== null ? (string) config($configKey, $logicalColumn) : null,
			$logicalColumn,
			strtoupper($logicalColumn),
			strtolower($logicalColumn),
		])));

		foreach ($candidates as $column) {
			$value = $bidUrl->getAttribute($column);
			if ($value !== null && $value !== '') {
				return $value;
			}
		}

		return $bidUrl->getAttribute($logicalColumn);
	}

	private function readBidUrlAssigneeId(BidUrl $bidUrl): ?int
	{
		$raw = $this->readBidUrlColumn($bidUrl, 'user_id');
		if ($raw === null || $raw === '') {
			return null;
		}

		return (int) $raw;
	}

	private function moveBidUrlToFailed(BidUrl $bidUrl, string $message): void
	{
		$url = trim((string) ($this->readBidUrlColumn($bidUrl, 'url') ?? ''));
		$failedBidUrl = FailedBidUrl::firstOrNew([
			'url' => $url !== '' ? $url : $bidUrl->url,
		]);

		$failedBidUrl->fill([
			'original_bid_url_id' => $bidUrl->getKey(),
			'url' => $url !== '' ? $url : $bidUrl->url,
			'name' => $this->readBidUrlColumn($bidUrl, 'name'),
			'start_time' => $bidUrl->start_time,
			'end_time' => $bidUrl->end_time,
			'weight' => $bidUrl->weight,
			'user_id' => $this->readBidUrlAssigneeId($bidUrl),
			'check_changes' => $bidUrl->check_changes,
			'visit_required' => $bidUrl->visit_required,
			'checksum' => $bidUrl->checksum,
			'valid' => $bidUrl->valid,
			'third_party_url_id' => $bidUrl->third_party_url_id,
			'username' => $bidUrl->username,
			'password' => $bidUrl->password,
			'last_scraped_at' => $bidUrl->last_scraped_at,
			'failure_message' => $message,
			'failed_at' => now(),
		]);
		$failedBidUrl->save();

		Log::warning('BIDURL quarantined to failed_bid_urls after scrape failure', [
			'bid_url_id' => $bidUrl->getKey(),
			'user_id' => $failedBidUrl->user_id,
			'url' => $failedBidUrl->url,
			'reason' => $message,
		]);

		$bidUrl->delete();
	}

	public function issues()
	{
		$logs = ScrapeLog::latest('created_at')->paginate(50);
		return view('issues.index', compact('logs'));
	}

	public function clearIssues()
	{
		ScrapeLog::truncate();
		return redirect()->route('bids.index')->with('success', 'All issues cleared.');
	}

	public function destroyIssue(Request $request, ScrapeLog $scrapeLog)
	{
		$scrapeLog->delete();

		if ($request->expectsJson()) {
			return response()->json(['ok' => true]);
		}

		return redirect()->route('scrape.issues')->with('success', 'Issue deleted.');
	}

	/**
	 * JSON for bid edit ENTITYID search (authenticated).
	 * Query params: optional id=int (single label), optional q=str, optional limit (10–100).
	 */
	public function referenceEntitiesSearch(Request $request, BidReferenceLookupService $lookup)
	{
		if ($request->filled('id')) {
			$id = (int) $request->query('id', 0);

			return response()->json([
				'resolved' => $id > 0 ? $lookup->getEntityOptionById($id) : null,
			]);
		}

		$limit = (int) $request->query('limit', 40);
		$limit = max(10, min(100, $limit));

		return response()->json([
			'results' => $lookup->searchEntitiesForSelect($request->string('q')->toString(), $limit),
		]);
	}

	/**
	 * JSON for bid / pending edit STATEID search (authenticated).
	 * Query params: optional id=int (single label), optional q=str, optional limit (10–100).
	 */
	public function referenceStatesSearch(Request $request, BidReferenceLookupService $lookup)
	{
		if ($request->filled('id')) {
			$id = (int) $request->query('id', 0);

			return response()->json([
				'resolved' => $id > 0 ? $lookup->getStateOptionById($id) : null,
			]);
		}

		$limit = (int) $request->query('limit', 50);
		$limit = max(10, min(100, $limit));

		return response()->json([
			'results' => $lookup->searchStatesForSelect($request->string('q')->toString(), $limit),
		]);
	}

	/**
	 * JSON for bid / pending edit CATEGORYID search (authenticated).
	 * Query params: optional id=int (single label), optional q=str, optional limit (10–100).
	 */
	public function referenceCategoriesSearch(Request $request, BidReferenceLookupService $lookup)
	{
		if ($request->filled('id')) {
			$id = (int) $request->query('id', 0);

			return response()->json([
				'resolved' => $id > 0 ? $lookup->getCategoryOptionById($id) : null,
			]);
		}

		$limit = (int) $request->query('limit', 50);
		$limit = max(10, min(100, $limit));

		return response()->json([
			'results' => $lookup->searchCategoriesForSelect($request->string('q')->toString(), $limit),
		]);
	}

	/**
	 * JSON for bid / pending edit BID_URL_ID search (authenticated).
	 * Query params: optional id=int (single label), optional q=str, optional user_id, optional limit (10–100).
	 */
	public function referenceBidUrlsSearch(Request $request, BidUrlManualEntryService $entries)
	{
		if ($request->filled('id')) {
			$id = (int) $request->query('id', 0);
			$opt = $id > 0 ? $entries->getBidUrlOptionById($id) : null;

			return response()->json([
				'resolved' => $opt ? ['id' => $opt['id'], 'label' => $opt['label']] : null,
			]);
		}

		$limit = (int) $request->query('limit', 40);
		$limit = max(10, min(100, $limit));
		$userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
		if ($userId !== null && $userId <= 0) {
			$userId = null;
		}

		$results = $entries->searchBidUrlsForSelect($request->string('q')->toString(), $limit, $userId);

		return response()->json([
			'results' => array_map(
				static fn (array $row) => ['id' => $row['id'], 'label' => $row['label']],
				$results
			),
		]);
	}

	/** JSON: live bids added for Reports tab drill-down (by CREATED). */
	public function reportsBidsAdded(
		Request $request,
		BidReportsService $reports,
		BidReferenceLookupService $lookup,
		BidUrlManualEntryService $bidUrls,
	) {
		$userId = (int) $request->query('user_id', 0);
		[$from, $to] = $this->parseReportRange($request, $reports);

		$manilaUsers = [];
		try {
			$manilaUsers = $lookup->getManilaAssignableUsersForSelect();
		} catch (\Throwable $e) {
			Log::warning('Reports bids added: Manila users not loaded', ['error' => $e->getMessage()]);
		}

		$manilaIds = collect($manilaUsers)
			->map(fn (array $u) => (int) $u['id'])
			->filter(fn (int $id) => $id > 0)
			->values()
			->all();

		if ($userId > 0 && !in_array($userId, $manilaIds, true)) {
			return response()->json(['error' => 'Invalid user.'], 422);
		}

		$userLabel = 'All users';
		if ($userId > 0) {
			foreach ($manilaUsers as $user) {
				if ((int) $user['id'] === $userId) {
					$userLabel = (string) ($user['label'] ?? ('User #' . $userId));
					break;
				}
			}
		}

		$rows = $reports->bidsAddedListing($userId, $from, $to, $manilaIds, $lookup, $bidUrls);

		return response()->json([
			'user_id' => $userId,
			'user_label' => $userLabel,
			'from' => $from->timezone('Asia/Manila')->toDateString(),
			'to' => $to->timezone('Asia/Manila')->toDateString(),
			'count' => count($rows),
			'rows' => $rows,
		]);
	}

	public function update(Request $request, Bid $bid)
	{
		$nullableStrings = ['DESCRIPTION', 'EMAIL', 'URL', 'NAICSCODE', 'SOLICIATIONNUMBER', 'THIRD_PARTY_IDENTIFIER', 'NSN', 'INLINEURL', 'COUNTRY_ID', 'raw_html', 'extracted_json'];
		foreach ($nullableStrings as $key) {
			if ($request->input($key) === '') {
				$request->merge([$key => null]);
			}
		}
		$nullableInts = ['CATEGORYID', 'ENTITYID', 'SUBSCRIPTIONTYPEID', 'USERID', 'SETASIDECODEID', 'BID_URL_ID', 'SOURCE_ID', 'STATEID', 'CATEGORY_ALIAS_ID', 'NAICSCODE_INT'];
		foreach ($nullableInts as $key) {
			if ($request->input($key) === '' || $request->input($key) === null) {
				$request->merge([$key => null]);
			}
		}
		foreach (['ENDDATE', 'FEDDATE', 'CREATED', 'LAST_MODIFIED'] as $key) {
			if ($request->input($key) === '') {
				$request->merge([$key => null]);
			}
		}

		$validated = $request->validate([
			'TITLE' => ['required', 'string', 'max:255'],
			'DESCRIPTION' => ['nullable', 'string'],
			'EMAIL' => ['nullable', 'email', 'max:255'],
			'URL' => ['nullable', 'string', 'max:2048'],
			'ENDDATE' => ['nullable', 'date'],
			'NAICSCODE' => ['nullable', 'string', 'max:255'],
			'SOLICIATIONNUMBER' => ['nullable', 'string', 'max:255'],
			'FEDDATE' => ['nullable', 'date'],
			'THIRD_PARTY_IDENTIFIER' => ['nullable', 'string', 'max:255'],
			'NSN' => ['nullable', 'string', 'max:255'],
			'CREATED' => ['nullable', 'date'],
			'LAST_MODIFIED' => ['nullable', 'date'],
			'INLINEURL' => ['nullable', 'string', 'max:500'],
			'CATEGORYID' => ['nullable', 'integer'],
			'ENTITYID' => ['nullable', 'integer'],
			'SUBSCRIPTIONTYPEID' => ['nullable', 'integer'],
			'USERID' => ['nullable', 'integer'],
			'SETASIDECODEID' => ['nullable', 'integer'],
			'BID_URL_ID' => ['nullable', 'integer'],
			'SOURCE_ID' => ['nullable', 'integer'],
			'STATEID' => ['nullable', 'integer'],
			'CATEGORY_ALIAS_ID' => ['nullable', 'integer'],
			'NAICSCODE_INT' => ['nullable', 'integer'],
			'COUNTRY_ID' => ['nullable', 'string', 'max:32'],
			'USERID' => [
				'nullable',
				'integer',
				function (string $attribute, mixed $value, \Closure $fail) use ($bid) {
					if ($value === null || $value === '') {
						return;
					}
					$intVal = (int) $value;
					$manilaIds = collect(app(BidReferenceLookupService::class)->getManilaAssignableUsersForSelect())
						->map(fn (array $r) => (int) $r['id'])
						->all();
					if (in_array($intVal, $manilaIds, true)) {
						return;
					}
					$current = (int) ($bid->USERID ?? 0);
					if ($current !== 0 && $current === $intVal) {
						return;
					}
					$fail('Choose a user from the Manila (Asia/Manila) list.');
				},
			],
			'raw_html' => ['nullable', 'string'],
			'extracted_json' => ['nullable', 'string'],
		], [
			'TITLE.required' => 'Title is required.',
			'TITLE.max' => 'Title is too long. Please keep it under 255 characters.',
			'ENDDATE.date' => 'End date must be a valid date.',
			'FEDDATE.date' => 'Fed date must be a valid date.',
			'CREATED.date' => 'Created must be a valid date/time.',
			'LAST_MODIFIED.date' => 'Last modified must be a valid date/time.',
		]);

		$validated['ENDDATE'] = $this->sanitizeDate($validated['ENDDATE'] ?? null);
		$validated['FEDDATE'] = $this->sanitizeDate($validated['FEDDATE'] ?? null);
		if (!empty($validated['CREATED'])) {
			try {
				$validated['CREATED'] = \Carbon\Carbon::parse($validated['CREATED']);
			} catch (\Throwable $e) {
				$validated['CREATED'] = null;
			}
		} else {
			$validated['CREATED'] = null;
		}
		if (!empty($validated['LAST_MODIFIED'])) {
			try {
				$validated['LAST_MODIFIED'] = \Carbon\Carbon::parse($validated['LAST_MODIFIED']);
			} catch (\Throwable $e) {
				$validated['LAST_MODIFIED'] = now();
			}
		} else {
			$validated['LAST_MODIFIED'] = now();
		}

		$validated['NEEDS_REVIEW'] = $request->has('NEEDS_REVIEW') && (string) $request->input('NEEDS_REVIEW') !== '0' ? 1 : 0;
		$validated['UNDERREVIEW'] = $request->has('UNDERREVIEW') && (string) $request->input('UNDERREVIEW') !== '0' ? 1 : 0;

		$bid->update($this->filterBidUpdateAttributes($bid, $validated));

		return redirect()->route('bids.index')->with('success', 'Bid updated successfully.');
	}

	/**
	 * Match blank reference IDs stored as NULL or 0 (Oracle/MySQL).
	 */
	private function applyBidMissingNumericIdFilter(\Illuminate\Database\Eloquent\Builder $query, string $column): void
	{
		$resolved = $this->resolveBidTableColumn($column) ?? $column;
		$driver = $query->getConnection()->getDriverName();
		$expr = in_array($driver, ['oracle', 'oci8'], true)
			? "NVL({$resolved}, 0) = 0"
			: "COALESCE({$resolved}, 0) = 0";
		$query->whereRaw($expr);
	}

	private function resolveBidTableColumn(string $preferred): ?string
	{
		static $cache = null;
		if ($cache === null) {
			try {
				$cache = Schema::getColumnListing((new Bid())->getTable());
			} catch (\Throwable $e) {
				Log::warning('Bid listing: could not read bid table columns', ['error' => $e->getMessage()]);
				$cache = [];
			}
		}
		foreach ($cache as $col) {
			if (strcasecmp((string) $col, $preferred) === 0) {
				return (string) $col;
			}
		}

		return null;
	}

	/**
	 * Oracle and other legacy schemas may omit columns that exist only on MySQL (e.g. raw_html, extracted_json).
	 */
	private function filterBidUpdateAttributes(Bid $bid, array $validated): array
	{
		return BidLiveColumnFilter::filterForWrite($validated);
	}

	private function applyCorporateTitlePrefix(string $title, ?string $postingEntity): string
	{
		$t = trim($title);
		if ($t === '') {
			return $title;
		}
		if (strtolower(trim((string) ($postingEntity ?? ''))) !== 'private_company') {
			return $title;
		}
		if (preg_match('/^Corporate\s*:\s+/iu', $t)) {
			return $title;
		}

		return 'Corporate: ' . $t;
	}

	private function resolveBidContactEmail(array $bidData, string $description): ?string
	{
		$fromAi = $this->sanitizeBidEmailForColumn($bidData['CONTACT_EMAIL'] ?? null);
		if ($fromAi !== null) {
			return $fromAi;
		}

		return $this->extractFirstEmailFromText($description);
	}

	private function sanitizeBidEmailForColumn(mixed $raw): ?string
	{
		if (is_array($raw)) {
			$raw = implode(' ', $raw);
		}
		$s = trim((string) ($raw ?? ''));
		if ($s === '' || strcasecmp($s, 'not provided') === 0) {
			return null;
		}
		foreach (preg_split('/[\s,;|]+/', $s) as $part) {
			$part = trim($part);
			if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
				return mb_substr($part, 0, 255);
			}
		}

		return null;
	}

	private function extractFirstEmailFromText(string $text): ?string
	{
		if ($text === '') {
			return null;
		}
		if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', $text, $m) && isset($m[0])) {
			foreach ($m[0] as $candidate) {
				$v = $this->sanitizeBidEmailForColumn($candidate);
				if ($v !== null) {
					return $v;
				}
			}
		}

		return null;
	}

	private function applyBidReferenceFieldsFromScrape(
		Model $bid,
		array $bidData,
		string $title,
		string $description,
		?string $sourceListingUrl = null,
		?string $bidUrlName = null
	): void {
		try {
			$lookup = app(BidReferenceLookupService::class);
			$catId = $lookup->resolveCategoryId($bidData['BID_CATEGORY'] ?? null, $title, $description);
			if ($catId !== null) {
				$bid->CATEGORYID = $catId;
			}
			$stateId = $lookup->resolveStateId($bidData['LOCATION_STATE'] ?? null, $description, $title);
			if ($stateId !== null) {
				$bid->STATEID = $stateId;
			}
			$entityId = $lookup->resolveEntityId(
				isset($bidData['ISSUING_ORGANIZATION']) ? (string) $bidData['ISSUING_ORGANIZATION'] : null,
				$bid->EMAIL,
				(string) ($bid->URL ?? ''),
				$title,
				$description,
				$sourceListingUrl,
				$bidUrlName
			);
			if ($entityId !== null && $entityId > 0) {
				$bid->ENTITYID = $entityId;
			}
		} catch (\Throwable $e) {
			Log::warning('Bid classification lookup failed', ['error' => $e->getMessage()]);
		}
	}

	/** Optional Manila directory user to set on newly scraped bids (query: assign_user_id). */
	private function resolveOptionalManilaAssignUserId(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			return null;
		}
		$intVal = (int) $value;
		if ($intVal <= 0) {
			return null;
		}
		$allowed = collect(app(BidReferenceLookupService::class)->getManilaAssignableUsersForSelect())
			->map(fn (array $r) => (int) $r['id'])
			->all();
		if (!in_array($intVal, $allowed, true)) {
			Log::warning('assign_user_id ignored: unknown or non-Manila user', ['id' => $intVal]);

			return null;
		}

		return $intVal;
	}

	private function applyScrapeAssignUserId(Model $bid, ?int $assignUserId): void
	{
		if ($assignUserId !== null) {
			$bid->USERID = $assignUserId;
		}
	}

	/**
	 * Defaults for scraped bids before save (not used for manual UI creates).
	 * SUBSCRIPTIONTYPEID / SETASIDECODEID match production expectations for auto-imported rows.
	 */
	private function applyScrapeCreatedBidDefaults(Model $bid): void
	{
		$bid->SUBSCRIPTIONTYPEID = 10;
		$bid->SETASIDECODEID = 1;
	}

	public function destroy(Bid $bid)
	{
		$bid->delete();
		return redirect()->route('bids.index')->with('success', 'Bid deleted successfully.');
	}

	private function formatDescriptionArray(array $data, string $indent = ''): string
	{
		$lines = [];
		foreach ($data as $key => $value) {
			$label = is_int($key) ? $key : $key;
			if (is_array($value)) {
				$lines[] = "{$indent}{$label}:";
				$lines[] = $this->formatDescriptionArray($value, $indent . '  ');
			} else {
				$lines[] = "{$indent}{$label}: {$value}";
			}
		}
		return implode("\n", array_filter($lines));
	}

	/**
	 * Remove lines that are empty, "Not provided", or NAICS labels.
	 */
	private function stripNotProvidedLines(?string $description): string
	{
		if (empty($description)) {
			return '';
		}

		$lines = preg_split('/\r\n|\r|\n/', $description) ?: [];
		$clean = array_filter($lines, function ($line) {
			$trimmed = trim($line);
			if ($trimmed === '') {
				return false;
			}
			if (stripos($trimmed, 'not provided') !== false) {
				return false;
			}
			// Drop NAICS entries from description.
			if (stripos($trimmed, 'naics') === 0) {
				return false;
			}
			return true;
		});

		return trim(implode("\n", $clean));
	}

	/**
	 * Append important details if missing: title, end date, NAICS, and source URL.
	 */
	private function augmentDescription(string $description, ?string $title, ?string $endDate, $naics, ?string $url): string
	{
		$lines = preg_split('/\r\n|\r|\n/', $description) ?: [];
		$normalized = array_map('trim', $lines);

		$ensure = function (string $label, ?string $value) use (&$normalized) {
			$val = trim((string) $value);
			if ($val === '') {
				return;
			}
			$lowerLabel = strtolower($label);
			$already = array_filter($normalized, fn($line) => str_starts_with(strtolower($line), $lowerLabel));
			if (empty($already)) {
				$normalized[] = "{$label}: {$val}";
			}
		};

		$ensure('Title', $title);
		$ensure('End Date', $endDate);
		$ensure('Bid URL', $url);

		return trim(implode("\n", array_filter($normalized, fn($line) => $line !== '')));
	}

	/**
	 * Normalize NAICS to a safe DB value:
	 * - If a census NAICS URL is provided, pull the ?input=<code> value.
	 * - Otherwise, extract the first 2-6 digit sequence.
	 * - Truncate to 6 characters and strip non-digits.
	 */
	private function normalizeNaicsCode($value, ?string $fallbackText = null, ?string $title = null, ?string $url = null): ?string
	{
		$raw = trim(is_array($value) ? implode(' ', $value) : (string) $value);
		if ($raw === '' || strcasecmp($raw, 'not provided') === 0) {
			$raw = '';
		}

		// If it looks like a census NAICS URL, read the input query param.
		if (str_contains(strtolower($raw), 'census.gov/naics')) {
			$parts = parse_url($raw);
			if (!empty($parts['query'])) {
				parse_str($parts['query'], $query);
				if (!empty($query['input'])) {
					$raw = (string) $query['input'];
				}
			}
		}

		// Extract first 2-6 digit sequence and validate.
		if ($raw !== '' && preg_match('/(\d{2,6})/', $raw, $matches)) {
			$candidate = substr($matches[1], 0, 6);
			if ($this->isValidNaicsCandidate($candidate)) {
				return $candidate;
			}
		}

		// Look for "NAICS" followed by digits inside description/title/url.
		$candidates = [$fallbackText, $title, $url];
		foreach ($candidates as $text) {
			if (empty($text)) {
				continue;
			}
			if (preg_match('/NAICS[^0-9]{0,10}(\d{2,6})/i', $text, $m)) {
				$candidate = substr($m[1], 0, 6);
				if ($this->isValidNaicsCandidate($candidate)) {
					return $candidate;
				}
			}
		}

		// Heuristic: map common categories to NAICS codes when none provided.
		$heuristic = $this->inferNaicsFromText(($fallbackText ?? '') . ' ' . ($title ?? ''));
		if ($heuristic !== null) {
			return $heuristic;
		}

		// Secondary scan: pick first valid digit sequence anywhere in description/title/url.
		foreach ($candidates as $text) {
			if (empty($text)) {
				continue;
			}
			if (preg_match('/(\d{2,6})/', $text, $m)) {
				$candidate = substr($m[1], 0, 6);
				if ($this->isValidNaicsCandidate($candidate)) {
					return $candidate;
				}
			}
		}

		// Final fallback to a valid, generic public admin NAICS to avoid null saves.
		return '921190';
	}

	// Very small keyword heuristic to avoid random numbers; extend as needed.
	private function inferNaicsFromText(string $text): ?string
	{
		$text = strtolower($text);
		$map = [
			['keywords' => ['software', 'saas', 'it services', 'cloud', 'system', 'platform'], 'code' => '541512'],
			['keywords' => ['data center', 'hosting'], 'code' => '518210'],
			['keywords' => ['record', 'records management', 'document management', 'archives', 'land records'], 'code' => '519190'],
			['keywords' => ['web', 'website'], 'code' => '541511'],
			['keywords' => ['cyber', 'security'], 'code' => '541519'],
			['keywords' => ['consulting', 'professional services'], 'code' => '541611'],
			['keywords' => ['engineering'], 'code' => '541330'],
			['keywords' => ['telecom', 'telecommunications'], 'code' => '517919'],
			['keywords' => ['janitorial', 'custodial', 'cleaning'], 'code' => '561720'],
			['keywords' => ['landscape', 'mowing', 'grounds', 'lawn'], 'code' => '561730'],
			['keywords' => ['waste', 'trash', 'recycling', 'garbage'], 'code' => '562111'],
			['keywords' => ['construction', 'renovation', 'remodel', 'building'], 'code' => '236220'],
			['keywords' => ['roof', 'roofing'], 'code' => '238160'],
			['keywords' => ['electrical'], 'code' => '238210'],
			['keywords' => ['hvac', 'mechanical', 'plumbing'], 'code' => '238220'],
			['keywords' => ['road', 'street', 'paving', 'asphalt', 'bridge'], 'code' => '237310'],
		];

		foreach ($map as $entry) {
			foreach ($entry['keywords'] as $kw) {
				if (str_contains($text, $kw)) {
					return $entry['code'];
				}
			}
		}

		return null;
	}

	private function isValidNaicsCandidate(string $code): bool
	{
		$code = trim($code);
		if ($code === '' || !ctype_digit($code)) {
			return false;
		}

		$len = strlen($code);
		if ($len < 2 || $len > 6) {
			return false;
		}

		$validTwoDigit = [
			'11',
			'21',
			'22',
			'23',
			'31',
			'32',
			'33',
			'42',
			'44',
			'45',
			'48',
			'49',
			'51',
			'52',
			'53',
			'54',
			'55',
			'56',
			'61',
			'62',
			'71',
			'72',
			'81',
			'92',
		];

		if (!in_array(substr($code, 0, 2), $validTwoDigit, true)) {
			return false;
		}

		// Reject known bad 926xxx combinations outside 2022 table.
		if ($len === 6 && substr($code, 0, 3) === '926' && !in_array($code, ['926110', '926120', '926130', '926140', '926150', '926160'])) {
			return false;
		}

		return true;
	}

	private function sanitizeDate($value): ?string
	{
		$value = is_string($value) ? trim($value) : $value;
		if (empty($value)) {
			return null;
		}

		try {
			return \Carbon\Carbon::parse($value)->toDateString();
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function friendlyExceptionMessage(\Throwable $e): string
	{
		$rawMessage = $e->getMessage();
		if (str_contains(strtolower($rawMessage), 'login credentials')) {
			return $rawMessage;
		}

		if ($e instanceof RequestException) {
			$status = $e->getResponse()?->getStatusCode();
			if ($status === 403) {
				return 'The site blocked our request (403 Forbidden). Please try opening the link in a browser first or use a different source.';
			}
			if ($status === 404) {
				return 'The page could not be found (404). Please double-check the link.';
			}
			if ($status === 522 || str_contains(strtolower($e->getMessage()), 'timed out')) {
				return 'The site took too long to respond. Please try again later.';
			}
			if ($status >= 500) {
				return 'The site returned a server error. Please retry after a few minutes.';
			}
		}

		$message = strtolower($e->getMessage());
		if (str_contains($message, 'extract exceeded maximum wall-clock time')) {
			return 'AI extraction took too long for this URL and was skipped. It will be retried on the next run.';
		}
		if (str_contains($message, 'timed out')) {
			return 'The site took too long to respond. Please try again later.';
		}
		if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
			return 'We could not establish a secure connection to this site. Please verify the link or try another URL.';
		}

		return 'Failed to scrape this URL. ' . $rawMessage;
	}

	private function scrapeUrlMaxSeconds(): int
	{
		return max(120, min(7200, (int) config('scraper.scrape_url_max_seconds', 480)));
	}

	/**
	 * User-facing SSE line before synchronous OpenAI extract (streaming stays idle until completion).
	 */
	private function sseStatusStepAiBidExtract(int $listingTextChars, int $pdfTextChars, int $bidPageCount): string
	{
		$heavy = $listingTextChars >= 35000 || $pdfTextChars >= 28000 || $bidPageCount >= 3;
		if ($heavy) {
			return 'Extracting bids with AI… Heavy listing/PDF or multiple detail pages: OpenAI often needs ½–4 min. SSE stays quiet until it returns — watch “Elapsed this step”.';
		}
		if ($listingTextChars >= 4000) {
			return 'Extracting bids with AI… Busy listing tables can imply many bids and a large JSON response — OpenAI often needs 1–3+ min. SSE stays quiet — watch “Elapsed this step”.';
		}

		return 'Extracting bids with AI… Typical 15s–2 min; SSE is quiet until OpenAI responds — watch “Elapsed this step”.';
	}

	private function scrapeTitleRewriteReserveSeconds(): int
	{
		return max(30, min(900, (int) config('scraper.scrape_title_rewrite_reserve_seconds', 90)));
	}

	private function scrapeRewriteMaxTitles(): int
	{
		return max(10, min(500, (int) config('scraper.scrape_rewrite_max_titles', 120)));
	}

	private function scrapeTitleRewriteChunkTitles(): int
	{
		return max(4, min(35, (int) config('scraper.title_rewrite_chunk_titles', 10)));
	}

	/**
	 * Optionally skip batched rewrite (second OpenAI call) near time budget / huge title lists.
	 *
	 * @param callable|null $progress function (string $event, array $context):void — events: start, chunk, skip_time, skip_many
	 * @param ?int           $urlMaxSecondsBudget override per-row cap (e.g. 300 for scrape-stream “single per-URL max”).
	 */
	private function maybeRewriteScrapeTitles(AIExtractor $ai, array $rawTitles, float $urlStartedAt, string $url, ?callable $progress, ?int $urlMaxSecondsBudget = null): array
	{
		if ($rawTitles === []) {
			return [];
		}

		$maxSec = $urlMaxSecondsBudget !== null
			? max(60, min(7200, $urlMaxSecondsBudget))
			: $this->scrapeUrlMaxSeconds();

		$maxTitles = $this->scrapeRewriteMaxTitles();
		if (count($rawTitles) > $maxTitles) {
			Log::warning('Title rewrite skipped: too many opportunities for one batched call', [
				'url' => $url,
				'title_count' => count($rawTitles),
				'max_allowed' => $maxTitles,
			]);
			if ($progress !== null) {
				$progress('skip_many', ['count' => count($rawTitles)]);
			}

			return $rawTitles;
		}

		$elapsed = microtime(true) - $urlStartedAt;
		$reserve = $this->scrapeTitleRewriteReserveSeconds();
		if ($elapsed > $maxSec - $reserve) {
			Log::warning('Title rewrite skipped: URL nearing per-row time budget', [
				'url' => $url,
				'elapsed_sec' => round($elapsed, 2),
				'budget_sec' => $maxSec,
				'reserve_sec' => $reserve,
			]);
			if ($progress !== null) {
				$progress('skip_time', ['elapsed_sec' => round($elapsed, 2), 'budget_sec' => $maxSec]);
			}

			return $rawTitles;
		}

		$titlesFlat = array_values($rawTitles);
		$n = count($titlesFlat);
		$chunkSz = max(1, min($n, $this->scrapeTitleRewriteChunkTitles()));
		$saveReserve = max(10, (int) config('scraper.scrape_save_reserve_seconds', 25));
		$minBatchSeconds = 8;

		if ($n <= $chunkSz) {
			if ($progress !== null) {
				$progress('start', ['count' => $n, 'chunks' => 1]);
			}

			$this->guardUrlBudget($urlStartedAt, $maxSec, $url);
			$batchCap = $maxSec - (microtime(true) - $urlStartedAt) - $saveReserve;
			if ($batchCap < $minBatchSeconds) {
				if ($progress !== null) {
					$progress('skip_time', ['budget_sec' => $maxSec]);
				}

				return $rawTitles;
			}

			return $ai->rewriteTitles($titlesFlat, $batchCap);
		}

		$batches = array_chunk($titlesFlat, $chunkSz);
		$batchesTotal = count($batches);
		if ($progress !== null) {
			$progress('start', ['count' => $n, 'chunks' => $batchesTotal]);
		}

		$merged = [];
		foreach ($batches as $i => $batch) {
			$this->guardUrlBudget($urlStartedAt, $maxSec, $url);
			// Stop rewriting once the remaining budget can't fit another batch plus the save reserve;
			// keep the original titles for the rest so the URL still finishes within its time limit.
			$batchCap = $maxSec - (microtime(true) - $urlStartedAt) - $saveReserve;
			if ($batchCap < $minBatchSeconds) {
				Log::warning('Title rewrite stopped mid-batch — remaining per-URL budget too low', [
					'url' => $url,
					'completed_batches' => $i,
					'total_batches' => $batchesTotal,
					'budget_sec' => $maxSec,
				]);
				if ($progress !== null) {
					$progress('skip_time', ['budget_sec' => $maxSec]);
				}
				for ($j = $i; $j < $batchesTotal; $j++) {
					$merged = array_merge($merged, $batches[$j]);
				}

				return $merged;
			}
			if ($i > 0 && $progress !== null) {
				$progress('chunk', ['chunk' => $i + 1, 'total_chunks' => $batchesTotal]);
			}
			$merged = array_merge($merged, $ai->rewriteTitles($batch, $batchCap));
		}

		return $merged;
	}

	private function guardUrlBudget(float $startedAt, int $maxSeconds, string $url): void
	{
		$elapsed = microtime(true) - $startedAt;
		if ($elapsed > $maxSeconds) {
			throw new \RuntimeException("Processing this URL took too long ({$this->formatElapsed($elapsed)}). Skipping to avoid blocking other URLs.");
		}
	}

	private function remainingUrlBudgetSeconds(float $startedAt, int $maxSeconds): int
	{
		return max(0, (int) floor($maxSeconds - (microtime(true) - $startedAt)));
	}

	private function isUrlBudgetTimeout(\Throwable $e): bool
	{
		$msg = $e->getMessage();

		return str_contains($msg, 'Processing this URL took too long')
			|| str_contains($msg, 'Scrape timed out while processing')
			|| str_contains($msg, 'Extract exceeded maximum wall-clock time');
	}

	private function formatElapsed(float $seconds): string
	{
		$m = (int) floor($seconds / 60);
		$s = (int) ($seconds - $m * 60);
		return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
	}

	/**
	 * Map raw scraped titles to rewritten titles; fall back safely on count mismatch.
	 *
	 * @param list<string> $rawTitles
	 * @param list<string> $rewrittenTitles
	 * @return array<string, string>
	 */
	private function buildScrapeTitleMap(array $rawTitles, array $rewrittenTitles, string $contextUrl = ''): array
	{
		if ($rawTitles === []) {
			return [];
		}

		$raw = array_values($rawTitles);
		$rewritten = array_values($rewrittenTitles);
		if (count($rewritten) !== count($raw)) {
			Log::warning('Title rewrite count mismatch; using original titles', [
				'url' => $contextUrl,
				'raw' => count($raw),
				'rewritten' => count($rewritten),
			]);
			$rewritten = $raw;
		}

		$map = array_combine($raw, $rewritten);

		return is_array($map) ? $map : [];
	}

	/**
	 * @param array<int, array{url?: string, title?: string}> $bidPages
	 */
	private function resolveScrapedBidUrl(string $listingUrl, array $bidData, array $bidPages, ?string $matchTitle = null): string
	{
		$detailUrl = trim((string) ($bidData['URL'] ?? $listingUrl));
		$detailUrl = $detailUrl !== '' ? $detailUrl : $listingUrl;
		$title = trim((string) ($matchTitle ?? $bidData['TITLE'] ?? ''));

		return ThirdPartyProcurementPortalUrl::resolveSavedBidUrl($listingUrl, $detailUrl, $bidPages, $title);
	}

	private function shouldSkipBidUrlScrapedToday(BidUrl $bidUrl): bool
	{
		if (!config('scraper.bid_skip_url_if_scraped_today', false)) {
			return false;
		}

		return $bidUrl->last_scraped_at && $bidUrl->last_scraped_at->isToday();
	}

	/**
	 * @param  array<string, mixed>  $bidData
	 */
	private function evaluateScrapedBidDuplicate(
		array $bidData,
		string $savedUrl,
		?int $bidUrlId,
		BidDuplicateMatcher $matcher,
		BidDuplicateRunTracker $runTracker,
	): ?BidDuplicateMatch {
		$identity = BidIdentity::fromScrapeExtract($bidData, $savedUrl, $bidUrlId);
		if ($runTracker->seen($identity)) {
			return new BidDuplicateMatch(
				tier: BidDuplicateMatch::TIER_A,
				table: 'in_run',
				recordId: 0,
				reason: 'in_run_tier_a',
			);
		}

		$match = $matcher->match($identity);
		if ($match !== null) {
			Log::info('Scraped bid duplicate match', [
				'tier' => $match->tier,
				'table' => $match->table,
				'reason' => $match->reason,
				'record_id' => $match->recordId,
				'url' => $identity->normalizedDetailUrl,
				'bid_url_id' => $identity->bidUrlId,
				'title' => $bidData['TITLE'] ?? null,
			]);
		}

		return $match;
	}

	private function looksLikeBid(?string $title, ?string $description, ?string $url, ?string $endDate, ?BidIdentity $identity = null): bool
	{
		$title = trim((string) $title);
		$desc = strtolower((string) $description);
		$url = strtolower((string) $url);
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		$requireEndDate = (bool) config('scraper.bid_require_enddate_for_save', true);
		if (empty($endDate)) {
			$hasStableKey = $identity !== null && $identity->hasTierAKey();
			if (!$hasStableKey && $requireEndDate) {
				return false;
			}
		}

		// Titles that are too short or generic are unlikely to be bids.
		if ($title === '' || strlen($title) < 5) {
			return false;
		}
		$genericTitles = ['home', 'contact', 'about', 'document', 'documents', 'news', 'events', 'calendar', 'bids.aspx', 'bidding page', 'portal', 'webs'];
		if (in_array(strtolower($title), $genericTitles, true)) {
			return false;
		}

		$bidKeywords = '(bid|bids|rfp|rfq|rfi|tender|solicitation|proposal|invitation)';

		// Strong signals in title, description, or URL.
		if (preg_match("/{$bidKeywords}/i", $title)) {
			return true;
		}
		if (preg_match("/{$bidKeywords}/i", $desc)) {
			return true;
		}
		if (preg_match("/{$bidKeywords}/i", $url)) {
			return true;
		}

		// Require some descriptive substance to avoid saving bare page text.
		if (strlen($desc) < 120) {
			return false;
		}

		// Blocked/geolocation pages should not be saved.
		$blockedSignals = ['blocked country', 'geolocation', 'watchguard', 'connection was denied because this country'];
		foreach ($blockedSignals as $sig) {
			if (str_contains($desc, $sig)) {
				return false;
			}
		}

		// Special-case: pr-webs portal without end date should not be saved unless it has real content.
		if ($host === 'pr-webs-customer.des.wa.gov' && empty($endDate)) {
			if (stripos($title, 'bidding page') !== false || strlen($desc) < 300 || stripos($desc, 'webs') !== false) {
				return false;
			}
		}

		return true;
	}

	/** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
	private function parseReportRange(Request $request, BidReportsService $reports): array
	{
		$default = $reports->defaultReportRange();
		$from = $default['from'];
		$to = $default['to'];
		$fromInput = trim((string) $request->query('report_from', ''));
		$toInput = trim((string) $request->query('report_to', ''));

		try {
			if ($fromInput !== '') {
				$from = \Illuminate\Support\Carbon::parse($fromInput, 'Asia/Manila')->startOfDay();
			}
			if ($toInput !== '') {
				$to = \Illuminate\Support\Carbon::parse($toInput, 'Asia/Manila')->endOfDay();
			}
			if ($from->gt($to)) {
				[$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
			}
		} catch (\Throwable $e) {
			$from = $default['from'];
			$to = $default['to'];
		}

		return [$from, $to];
	}
}
