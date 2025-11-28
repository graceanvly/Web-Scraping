<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Services\AIExtractor;
use App\Services\ScraperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BidController extends Controller
{
	public function index()
	{
		$bids = Bid::latest('CREATED')->paginate(50);
		return view('bids.index', compact('bids'));
	}

	public function store(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		@set_time_limit(180);
		@ini_set('max_execution_time', '180');
		@ini_set('memory_limit', '512M');

		$validated = $request->validate([
			'URL' => ['required', 'url']
		]);

		try {
			// 1) Fetch page data, PDFs, and clickable bid pages
			$result = $scraper->fetch($validated['URL']);
			if (!empty($result['no_open_bids'])) {
				return back()->withErrors([
					'URL' => 'No open bids found on this page.'
				]);
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

			// 3) Save bids
			foreach ($filteredBids as $bidData) {
				$title = $bidData['TITLE'] ?? null;
				if (!$title) {
					continue;
				}

				$exists = Bid::where('URL', $validated['URL'])
					->where('TITLE', $title)
					->exists();

				if ($exists) {
					$duplicates[] = $title;
					continue;
				}

				$description = $bidData['DESCRIPTION'] ?? '';
				if (is_array($description)) {
					$description = $this->formatDescriptionArray($description);
				}
				if (empty($description) && !empty($result['pdf_text'])) {
					$description = $result['pdf_text'];
				}
				if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? '')) {
					$description = $result['pdf_bids'][0]['PDF_LINK'];
				}

				$endDate = $bidData['ENDDATE'] ?? null;
				if (!empty($endDate)) {
					try {
						$endDate = \Carbon\Carbon::parse($endDate)->toDateString();
					} catch (\Exception $e) {
						$endDate = null;
					}
				}

				$bid = new Bid();
				$bid->URL = $validated['URL'];
				$bid->TITLE = $title;
				$bid->ENDDATE = $endDate;
				$bid->NAICSCODE = $bidData['NAICSCODE'] ?? null;
				$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
				$bid->CREATED = now();
				$bid->LAST_MODIFIED = now();
				$bid->save();

				$saved[] = $bid->id;
			}

			// 4) Response
			if (empty($saved) && !empty($duplicates)) {
				return back()->withErrors([
					'URL' => 'All bids already saved: ' . implode(', ', $duplicates)
				]);
			}

			if (empty($saved) && empty($duplicates)) {
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

			return redirect()->route('bids.index')->with('success', trim($msg));
		} catch (\Throwable $e) {
			Log::error('Scrape failed', [
				'url' => $validated['URL'],
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return back()->withErrors([
				'URL' => 'Failed to scrape this URL. ' . $e->getMessage()
			])->withInput();
		}
	}

	public function show(Bid $bid)
	{
		return view('bids.show', compact('bid'));
	}

	public function scrapeAll(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		@set_time_limit(600);
		@ini_set('max_execution_time', '600');
		@ini_set('memory_limit', '1G');

		$bidUrls = BidUrl::all();
		$totalSaved = 0;
		$totalDuplicates = 0;
		$scrapeIssues = [];
		$failedUrls = [];

		foreach ($bidUrls as $bidUrl) {
			try {
				$url = trim((string) ($bidUrl->URL ?? $bidUrl->url ?? ''));
				if ($url === '') {
					$scrapeIssues[] = "Record ID {$bidUrl->id} - missing URL, skipped.";
					continue;
				}

				// 1) Fetch data for each bid URL
				$result = $scraper->fetch($url);
				if (!empty($result['no_open_bids'])) {
					$scrapeIssues[] = "{$url} - no open bids listed.";
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

				// 3) Save valid bids
				foreach ($filteredBids as $bidData) {
					$title = $bidData['TITLE'] ?? null;
					if (!$title) {
						continue;
					}

					$exists = Bid::where('URL', $url)
						->where('TITLE', $title)
						->exists();

					if ($exists) {
						$duplicatesThisUrl++;
						continue;
					}

					$description = $bidData['DESCRIPTION'] ?? '';
					if (is_array($description)) {
						$description = $this->formatDescriptionArray($description);
					}
					if (empty($description) && !empty($result['pdf_text'])) {
						$description = $result['pdf_text'];
					}
					if (empty($description) && !empty($result['pdf_bids'][0]['PDF_LINK'] ?? '')) {
						$description = $result['pdf_bids'][0]['PDF_LINK'];
					}

					$endDate = $bidData['ENDDATE'] ?? null;
					if (!empty($endDate)) {
						try {
							$endDate = \Carbon\Carbon::parse($endDate)->toDateString();
						} catch (\Exception $e) {
							$endDate = null;
						}
					}

					$bid = new Bid();
					$bid->URL = $url;
					$bid->TITLE = $title;
					$bid->ENDDATE = $endDate;
					$bid->NAICSCODE = $bidData['NAICSCODE'] ?? null;
					$bid->DESCRIPTION = $description ?: 'No description or PDF link found.';
					$bid->CREATED = now();
					$bid->LAST_MODIFIED = now();
					$bid->save();

					$savedThisUrl++;
				}

				$totalSaved += $savedThisUrl;
				$totalDuplicates += $duplicatesThisUrl;

				Log::info('Scrape summary for ' . $url, [
					'saved' => $savedThisUrl,
					'duplicates' => $duplicatesThisUrl,
				]);

				// Surface silent misses so the UI can show which URLs returned nothing.
				if ($savedThisUrl === 0 && $duplicatesThisUrl === 0) {
					$scrapeIssues[] = "{$url} - no bids found.";
				}
			} catch (\Throwable $e) {
				Log::error('Scrape failed for URL: ' . $url, [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);
				$failedUrls[] = $url;
				$scrapeIssues[] = "{$url} - failed: {$e->getMessage()}";
			}
		}

		// 4) Build final response
		$msg = "{$totalSaved} new bid(s) saved.";
		if ($totalDuplicates > 0) {
			$msg .= " Skipped {$totalDuplicates} duplicate bid(s).";
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

	public function update(Request $request, Bid $bid)
	{
		$validated = $request->validate([
			'TITLE' => 'required|string|max:255',
			'ENDDATE' => 'nullable|date',
			'NAICSCODE' => 'nullable|string|max:50',
		]);

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
}
