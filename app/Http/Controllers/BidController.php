<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Models\FailedBidUrl;
use App\Models\ScrapeLog;
use App\Services\AIExtractor;
use App\Services\ScraperService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BidController extends Controller
{
	public function index(Request $request)
	{
		$perPage = (int) $request->integer('per_page', 50);
		if (!in_array($perPage, [5, 10, 25, 50, 100], true)) {
			$perPage = 50;
		}

		$search = trim((string) $request->query('search', ''));
		$filterDate = trim((string) $request->query('date', ''));
		$filterNaics = trim((string) $request->query('naics', ''));

		$query = Bid::query();

		if ($search !== '') {
			$query->where(function ($q) use ($search) {
				$q->where('TITLE', 'like', "%{$search}%")
					->orWhere('NAICSCODE', 'like', "%{$search}%")
					->orWhere('URL', 'like', "%{$search}%");
			});
		}

		if ($filterDate !== '') {
			$query->whereDate('CREATED', $filterDate);
		}

		if ($filterNaics !== '') {
			$query->where('NAICSCODE', $filterNaics);
		}

		$bids = $query->latest('CREATED')->paginate($perPage)->withQueryString();
		$naicsCodes = Bid::query()
			->whereNotNull('NAICSCODE')
			->where('NAICSCODE', '!=', '')
			->distinct()
			->orderBy('NAICSCODE')
			->pluck('NAICSCODE');
		$issueCount = ScrapeLog::count();
		$scrapeLogs = ScrapeLog::latest('created_at')->limit(200)->get();
		return view('bids.index', compact('bids', 'naicsCodes', 'issueCount', 'scrapeLogs', 'search', 'filterDate', 'filterNaics'));
	}

	public function store(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		// Allow more time/memory for heavy PDF pages to avoid timeouts during single-URL scrapes.
		@set_time_limit(300);
		@ini_set('max_execution_time', '300');
		@ini_set('memory_limit', '1024M');

		$validated = $request->validate([
			'URL' => ['required', 'url', 'max:2048', 'regex:/^https?:\\/\\//i'],
		], [
			'URL.required' => 'Please enter a link to a page that lists open bids.',
			'URL.url' => 'The link needs to be a valid URL (for example, https://example.gov/bids).',
			'URL.max' => 'The link is too long. Please paste a shorter, direct link to the bids page.',
			'URL.regex' => 'Only http:// or https:// links are supported.',
		]);

		try {
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

			// 2) Let AI extract relevant bid data from listing + clicked pages + PDFs
			$extracted = $ai->extract(
				$validated['URL'],
				$result['html'],
				$result['text'],
				$result['pdf_bids'] ?? [],
				$result['pdf_text'] ?? '',
				$result['bid_pages'] ?? []
			);

			$rawHtml = $result['html'] ?? '';
			$extractedJson = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

			$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
			$rewrittenTitles = $ai->rewriteTitles($rawTitles);
			$titleMap = array_combine($rawTitles, $rewrittenTitles);

			foreach ($filteredBids as $bidData) {
				$rawTitle = $bidData['TITLE'] ?? null;
				if (!$rawTitle) {
					continue;
				}
				$title = $titleMap[$rawTitle] ?? $rawTitle;

				$detailUrl = trim((string) ($bidData['URL'] ?? $validated['URL']));
				$detailUrl = $detailUrl !== '' ? $detailUrl : $validated['URL'];

				$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

				$exists = Bid::where('TITLE', $title)
					->where(function ($q) use ($detailUrl, $endDate) {
						$q->where('URL', $detailUrl);
						if ($endDate) {
							$q->orWhere(function ($q2) use ($endDate) {
								$q2->where('ENDDATE', $endDate);
							});
						}
					})
					->exists();

				if ($exists) {
					$duplicates[] = $title;
					continue;
				}

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

				if (!$this->looksLikeBid($title, $description, $validated['URL'], $endDate)) {
					$nonBids[] = $title;
					continue;
				}

				$bid = new Bid();
				$bid->URL = $detailUrl;
				$bid->TITLE = $title;
				$bid->ENDDATE = $endDate;
				$bid->NAICSCODE = $this->normalizeNaicsCode(
					$bidData['NAICSCODE'] ?? null,
					$description,
					$title,
					$validated['URL']
				);
				$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
				$bid->CREATED = now();
				$bid->LAST_MODIFIED = now();
				$bid->raw_html = $rawHtml;
				$bid->extracted_json = $extractedJson;
				$bid->save();

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
				$msg .= count($saved) . ' bid(s) saved. ';
			}
			if ($duplicates) {
				$msg .= count($duplicates) . ' duplicate bid(s) skipped.';
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
		}
	}

	public function show(Bid $bid)
	{
		return view('bids.show', compact('bid'));
	}

	public function scrapeAll(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '1G');

		$today = \Carbon\Carbon::today();
		$bidUrls = BidUrl::all();
		$totalSaved = 0;
		$totalDuplicates = 0;
		$totalSkipped = 0;
		$scrapeIssues = [];
		$failedUrls = [];

		foreach ($bidUrls as $bidUrl) {
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

				if ($bidUrl->last_scraped_at && $bidUrl->last_scraped_at->isToday()) {
					$totalSkipped++;
					continue;
				}

				// 1) Fetch data for each bid URL
				$result = $scraper->fetch($url, $bidUrl->username ?? null, $bidUrl->password ?? null);
				if (!empty($result['blocked'])) {
					$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
					$this->logIssue($bidUrl->id, $url, 'error', 'Blocked by site protection/firewall.' . $reason);
					$this->moveBidUrlToFailed($bidUrl, 'Blocked by site protection/firewall.' . $reason);
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
					$this->moveBidUrlToFailed($bidUrl, 'Page content could not be read.');
					$scrapeIssues[] = "{$url} - page content could not be read.";
					continue;
				}
				Log::info('SCRAPER DEBUG scrapeAll', [
					'url' => $url,
					'pdf_links' => $result['pdf_bids'] ?? [],
					'text_length' => strlen($result['text'] ?? ''),
					'clicked_bid_pages' => count($result['bid_pages'] ?? []),
				]);

				// 2) Extract bid data via AI
				$extracted = $ai->extract(
					$url,
					$result['html'],
					$result['text'],
					$result['pdf_bids'] ?? [],
					$result['pdf_text'] ?? '',
					$result['bid_pages'] ?? []
				);

				$rawHtml = $result['html'] ?? '';
				$extractedJson = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

				$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
				$rewrittenTitles = $ai->rewriteTitles($rawTitles);
				$titleMap = array_combine($rawTitles, $rewrittenTitles);

				foreach ($filteredBids as $bidData) {
					$rawTitle = $bidData['TITLE'] ?? null;
					if (!$rawTitle) {
						continue;
					}
					$title = $titleMap[$rawTitle] ?? $rawTitle;

					$detailUrl = trim((string) ($bidData['URL'] ?? $url));
					$detailUrl = $detailUrl !== '' ? $detailUrl : $url;

					$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

					$exists = Bid::where('TITLE', $title)
						->where(function ($q) use ($detailUrl, $endDate) {
							$q->where('URL', $detailUrl);
							if ($endDate) {
								$q->orWhere(function ($q2) use ($endDate) {
									$q2->where('ENDDATE', $endDate);
								});
							}
						})
						->exists();

					if ($exists) {
						$duplicatesThisUrl++;
						continue;
					}

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

					if (!$this->looksLikeBid($title, $description, $url, $endDate)) {
						$nonBidsThisUrl++;
						continue;
					}

					$bid = new Bid();
					$bid->URL = $detailUrl;
					$bid->TITLE = $title;
					$bid->ENDDATE = $endDate;
					$bid->NAICSCODE = $this->normalizeNaicsCode(
						$bidData['NAICSCODE'] ?? null,
						$description,
						$title,
						$url
					);
					$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
					$bid->CREATED = now();
					$bid->LAST_MODIFIED = now();
					$bid->BID_URL_ID = $bidUrl->id;
					$bid->raw_html = $rawHtml;
					$bid->extracted_json = $extractedJson;
					$bid->save();

					$savedThisUrl++;
				}

				$totalSaved += $savedThisUrl;
				$totalDuplicates += $duplicatesThisUrl;

				$bidUrl->last_scraped_at = now();
				$bidUrl->save();

				Log::info('Scrape summary for ' . $url, [
					'saved' => $savedThisUrl,
					'duplicates' => $duplicatesThisUrl,
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
					$this->moveBidUrlToFailed($bidUrl, $this->friendlyExceptionMessage($e));
				}
				$failedUrls[] = $url;
				$scrapeIssues[] = "{$url} - " . $this->friendlyExceptionMessage($e);
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

	public function scrapeStream(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '1G');

		if (session()->isStarted()) {
			session()->save();
		}

		return new StreamedResponse(function () use ($scraper, $ai) {
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
			$totalSkipped = 0;
			$totalIssues = 0;

			$send(['type' => 'start', 'total' => $total]);

			foreach ($bidUrls as $idx => $bidUrl) {
				$url = trim((string) ($bidUrl->URL ?? $bidUrl->url ?? ''));

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

				if ($bidUrl->last_scraped_at && $bidUrl->last_scraped_at->isToday()) {
					$totalSkipped++;
					$send(['type' => 'skip', 'index' => $idx + 1, 'url' => $url, 'reason' => 'Already scraped today']);
					continue;
				}

				$send(['type' => 'processing', 'index' => $idx + 1, 'url' => $url]);

				try {
					$result = $scraper->fetch($url, $bidUrl->username ?? null, $bidUrl->password ?? null);

					if (!empty($result['blocked'])) {
						$reason = $result['blocked_reason'] ? (' Reason: ' . $result['blocked_reason']) : '';
						$this->logIssue($bidUrl->id, $url, 'error', 'Blocked by site protection/firewall.' . $reason);
						$this->moveBidUrlToFailed($bidUrl, 'Blocked by site protection/firewall.' . $reason);
						$totalIssues++;
						$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => 'Blocked by site protection']);
						continue;
					}
					if (!empty($result['no_open_bids'])) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No open bids listed.');
						$totalIssues++;
						$send(['type' => 'done_url', 'index' => $idx + 1, 'url' => $url, 'saved' => 0, 'duplicates' => 0, 'message' => 'No open bids']);
						$bidUrl->last_scraped_at = now();
						$bidUrl->save();
						continue;
					}
					if (empty($result['html']) && empty($result['pdf_bids']) && empty($result['bid_pages'])) {
						$this->logIssue($bidUrl->id, $url, 'error', 'Page content could not be read.');
						$this->moveBidUrlToFailed($bidUrl, 'Page content could not be read.');
						$totalIssues++;
						$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => 'Could not read content']);
						continue;
					}

					$extracted = $ai->extract(
						$url,
						$result['html'],
						$result['text'],
						$result['pdf_bids'] ?? [],
						$result['pdf_text'] ?? '',
						$result['bid_pages'] ?? []
					);

					$rawHtml = $result['html'] ?? '';
					$extractedJson = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

					$rawTitles = $filteredBids->pluck('TITLE')->filter()->values()->all();
					$rewrittenTitles = $ai->rewriteTitles($rawTitles);
					$titleMap = array_combine($rawTitles, $rewrittenTitles);

					foreach ($filteredBids as $bidData) {
						$rawTitle = $bidData['TITLE'] ?? null;
						if (!$rawTitle)
							continue;
						$title = $titleMap[$rawTitle] ?? $rawTitle;

						$detailUrl = trim((string) ($bidData['URL'] ?? $url));
						$detailUrl = $detailUrl !== '' ? $detailUrl : $url;
						$endDate = $this->sanitizeDate($bidData['ENDDATE'] ?? null);

						$exists = Bid::where('TITLE', $title)
							->where(function ($q) use ($detailUrl, $endDate) {
								$q->where('URL', $detailUrl);
								if ($endDate) {
									$q->orWhere('ENDDATE', $endDate);
								}
							})->exists();

						if ($exists) {
							$duplicatesThisUrl++;
							continue;
						}

						$description = $bidData['DESCRIPTION'] ?? '';
						if (is_array($description))
							$description = $this->formatDescriptionArray($description);
						$description = $this->stripNotProvidedLines($description);
						if (empty($description) && !empty($result['pdf_text']))
							$description = $result['pdf_text'];
						if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? ''))
							$description = $result['pdf_bids'][0]['PDF_LINK'];

						if (!$this->looksLikeBid($title, $description, $url, $endDate)) {
							$nonBidsThisUrl++;
							continue;
						}

						$bid = new Bid();
						$bid->URL = $detailUrl;
						$bid->TITLE = $title;
						$bid->ENDDATE = $endDate;
						$bid->NAICSCODE = $this->normalizeNaicsCode($bidData['NAICSCODE'] ?? null, $description, $title, $url);
						$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
						$bid->CREATED = now();
						$bid->LAST_MODIFIED = now();
						$bid->BID_URL_ID = $bidUrl->id;
						$bid->raw_html = $rawHtml;
						$bid->extracted_json = $extractedJson;
						$bid->save();
						$savedThisUrl++;
					}

					$totalSaved += $savedThisUrl;
					$totalDuplicates += $duplicatesThisUrl;
					$bidUrl->last_scraped_at = now();
					$bidUrl->save();

					if ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl === 0) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found.');
						$totalIssues++;
					} elseif ($savedThisUrl === 0 && $duplicatesThisUrl === 0 && $nonBidsThisUrl > 0) {
						$this->logIssue($bidUrl->id, $url, 'warning', 'No bids found. Please check the URL.');
						$totalIssues++;
					}

					$send(['type' => 'done_url', 'index' => $idx + 1, 'url' => $url, 'saved' => $savedThisUrl, 'duplicates' => $duplicatesThisUrl]);

				} catch (\Throwable $e) {
					Log::error('Scrape failed for URL: ' . $url, ['error' => $e->getMessage()]);
					$this->logIssue($bidUrl->id, $url, 'error', $this->friendlyExceptionMessage($e));
					$this->moveBidUrlToFailed($bidUrl, $this->friendlyExceptionMessage($e));
					$totalIssues++;
					$send(['type' => 'error', 'index' => $idx + 1, 'url' => $url, 'message' => $this->friendlyExceptionMessage($e)]);
				}
			}

			$send([
				'type' => 'complete',
				'total_saved' => $totalSaved,
				'total_duplicates' => $totalDuplicates,
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

	private function moveBidUrlToFailed(BidUrl $bidUrl, string $message): void
	{
		$failedBidUrl = FailedBidUrl::firstOrNew([
			'url' => $bidUrl->url,
		]);

		$failedBidUrl->fill([
			'original_bid_url_id' => $bidUrl->id,
			'url' => $bidUrl->url,
			'name' => $bidUrl->name,
			'start_time' => $bidUrl->start_time,
			'end_time' => $bidUrl->end_time,
			'weight' => $bidUrl->weight,
			'user_id' => $bidUrl->user_id,
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

	public function update(Request $request, Bid $bid)
	{
		$validated = $request->validate([
			'TITLE' => 'required|string|max:255',
			'ENDDATE' => 'nullable|date',
			'NAICSCODE' => 'nullable|string|max:50',
		], [
			'TITLE.required' => 'Title is required.',
			'TITLE.max' => 'Title is too long. Please keep it under 255 characters.',
			'ENDDATE.date' => 'End Date must be a valid date in the format YYYY-MM-DD.',
			'NAICSCODE.max' => 'NAICS code must be 50 characters or fewer.',
		]);

		$validated['ENDDATE'] = $this->sanitizeDate($validated['ENDDATE'] ?? null);

		$bid->update($validated);

		return redirect()->route('bids.index')->with('success', 'Bid updated successfully.');
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
		if (str_contains($message, 'timed out')) {
			return 'The site took too long to respond. Please try again later.';
		}
		if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
			return 'We could not establish a secure connection to this site. Please verify the link or try another URL.';
		}

		return 'Failed to scrape this URL. ' . $rawMessage;
	}

	private function looksLikeBid(?string $title, ?string $description, ?string $url, ?string $endDate): bool
	{
		$title = trim((string) $title);
		$desc = strtolower((string) $description);
		$url = strtolower((string) $url);
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		// Require an end date to consider this a bid.
		if (empty($endDate)) {
			return false;
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
}
