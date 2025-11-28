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

				$endDate = $bidData['ENDDATE'] ?? null;
				if (!empty($endDate)) {
					try {
						$endDate = \Carbon\Carbon::parse($endDate)->toDateString();
					} catch (\Exception $e) {
						$endDate = null;
					}
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

				$bid = new Bid();
				$bid->URL = $validated['URL'];
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

					$endDate = $bidData['ENDDATE'] ?? null;
					if (!empty($endDate)) {
						try {
							$endDate = \Carbon\Carbon::parse($endDate)->toDateString();
						} catch (\Exception $e) {
							$endDate = null;
						}
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

					$bid = new Bid();
					$bid->URL = $url;
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
<<<<<<< ours
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
=======
>>>>>>> theirs
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
			'11', '21', '22', '23', '31', '32', '33', '42', '44', '45',
			'48', '49', '51', '52', '53', '54', '55', '56', '61', '62',
			'71', '72', '81', '92',
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
}
