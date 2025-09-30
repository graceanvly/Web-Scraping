<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\BidUrl;
use App\Services\AIExtractor;
use App\Services\ScraperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class BidController extends Controller
{
	public function index()
	{
		$bids = Bid::latest()->limit(10)->get();
		return view('bids.index', compact('bids'));
	}

	public function store(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		@set_time_limit(180);
		@ini_set('max_execution_time', '180');

		$validated = $request->validate([
			'url' => ['required', 'url']
		]);

		try {
			$result = $scraper->fetch($validated['url']);
			$extracted = $ai->extract($validated['url'], $result['html'], $result['text']);

			$rawHtmlToStore = $result['html'];
			$rawHtmlPath = null;

			// Handle large HTML
			if (is_string($rawHtmlToStore) && strlen($rawHtmlToStore) > 400000) {
				try {
					$filename = 'bids/' . uniqid('bid_', true) . '.html';
					Storage::disk('local')->put($filename, $rawHtmlToStore);
					$rawHtmlPath = storage_path('app/' . $filename);
					$rawHtmlToStore = null;
				} catch (\Throwable $e) {
					$rawHtmlToStore = substr($result['html'], 0, 380000);
				}
			}

			// ✅ Filter bids before saving
			$today = \Carbon\Carbon::today();
			$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
				$status = strtolower($bid['other_data']['status'] ?? '');
				if ($status !== 'open') {
					return false;
				}

				if (!empty($bid['end_date'])) {
					try {
						$end = \Carbon\Carbon::parse($bid['end_date']);
						if ($end->lt($today)) {
							return false; // skip expired bids
						}
					} catch (\Exception $e) {
						return false; // skip invalid dates
					}
				}

				return true;
			})->values()->all();

			$saved = [];
			foreach ($filteredBids as $bidData) {
				// 🔍 Check if this bid already exists
				$exists = Bid::where('url', $validated['url'])
					->where('title', $bidData['title'] ?? null)
					->exists();

				if ($exists) {
					$duplicates[] = $bidData['title'] ?? '(untitled bid)';
					continue;
				}

				$bid = new Bid();
				$bid->url = $validated['url'];
				$bid->title = $bidData['title'] ?? null;
				$bid->end_date = $bidData['end_date'] ?? null;
				$bid->naics_code = $bidData['naics_code'] ?? null;
				$bid->other_data = $bidData['other_data'] ?? [];
				$bid->raw_html = $rawHtmlToStore; // shared raw HTML
				$bid->extracted_json = $bidData;
				$bid->save();

				$saved[] = $bid->id;
			}
			// 🛑 If no bids saved and only duplicates → show error
			if (empty($saved) && !empty($duplicates)) {
				return back()->withErrors([
					'url' => 'All bids on this page were duplicates: ' . implode(', ', $duplicates)
				]);
			}

			// 🛑 If no bids at all
			if (empty($saved) && empty($duplicates)) {
				return back()->withErrors(['url' => 'No open bids found on this page.']);
			}

			// ✅ Success message
			$msg = '';
			if ($saved) {
				$msg .= count($saved) . ' bids scraped and saved successfully! ';
			}

			return redirect()->route('bids.index')->with('success', trim($msg));
		} catch (\Throwable $e) {
			Log::error('Scrape failed', ['error' => $e->getMessage()]);
			return back()->withErrors(['url' => 'Failed to scrape this URL. ' . $e->getMessage()])
				->withInput();
		}
	}


	public function show(Bid $bid)
	{
		return view('bids.show', compact('bid'));
	}
	public function scrapeAll(Request $request, ScraperService $scraper, AIExtractor $ai)
	{
		// Allow longer execution time for multiple URLs
		@set_time_limit(300);
		@ini_set('max_execution_time', '300');

		$bidUrls = BidUrl::all();
		$scraped = 0;
		$duplicates = 0;
		$errors = [];

		foreach ($bidUrls as $bidUrl) {
			try {
				$result = $scraper->fetch($bidUrl->url);
				$extracted = $ai->extract($bidUrl->url, $result['html'], $result['text']);

				$today = \Carbon\Carbon::today();
				$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
					$status = strtolower($bid['other_data']['status'] ?? '');
					if ($status !== 'open') {
						return false;
					}
					if (!empty($bid['end_date'])) {
						try {
							$end = \Carbon\Carbon::parse($bid['end_date']);
							if ($end->lt($today)) {
								return false;
							}
						} catch (\Exception $e) {
							return false;
						}
					}
					return true;
				})->values()->all();

				foreach ($filteredBids as $bidData) {
					$exists = Bid::where('url', $bidUrl->url)
						->where('title', $bidData['title'] ?? null)
						->exists();

					if ($exists) {
						$duplicates++;
						continue; // Skip duplicates
					}

					$bid = new Bid();
					$bid->url = $bidUrl->url;
					$bid->title = $bidData['title'] ?? null;
					$bid->end_date = $bidData['end_date'] ?? null;
					$bid->naics_code = $bidData['naics_code'] ?? null;
					$bid->other_data = $bidData['other_data'] ?? [];
					$bid->raw_html = $result['html'];
					$bid->extracted_json = $bidData;
					$bid->save();

					$scraped++;
				}
			} catch (\Throwable $e) {
				Log::error('Scrape failed for URL: ' . $bidUrl->url, [
					'error' => $e->getMessage()
				]);
				$errors[] = $bidUrl->url;
			}
		}

		// Build summary message
		$msg = "$scraped new bids scraped and saved.";
		if ($duplicates > 0) {
			$msg .= " Skipped $duplicates duplicate bids.";
		}
		if ($errors) {
			$msg .= " Failed URLs: " . implode(', ', $errors);
		}

		return redirect()->route('bids.index')->with('success', $msg);
	}

}
