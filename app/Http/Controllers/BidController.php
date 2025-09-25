<?php

namespace App\Http\Controllers;

use App\Models\Bid;
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
		// Extend execution time for heavy pages (headless browser rendering)
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
			$otherData = is_array($extracted['other_data'] ?? null) ? $extracted['other_data'] : [];

			// Avoid inserting very large HTML into MySQL (can trigger "server has gone away").
			// If large, store the HTML to storage and only keep a reference path in DB.
			if (is_string($rawHtmlToStore) && strlen($rawHtmlToStore) > 400000) { // ~400 KB threshold
				try {
					$filename = 'bids/' . uniqid('bid_', true) . '.html';
					Storage::disk('local')->put($filename, $rawHtmlToStore);
					$rawHtmlPath = storage_path('app/' . $filename);
					$rawHtmlToStore = null;
					$otherData['raw_html_path'] = $rawHtmlPath;
					$otherData['raw_html_note'] = 'Raw HTML stored on disk due to size.';
				} catch (\Throwable $e) {
					Log::warning('Failed to persist raw HTML to storage', ['error' => $e->getMessage()]);
					// As a fallback, truncate to a safe size
					$rawHtmlToStore = substr($result['html'], 0, 380000);
					$otherData['raw_html_note'] = 'HTML truncated due to size.';
				}
			}

			$bid = new Bid();
			$bid->url = $validated['url'];
			$bid->title = $extracted['title'] ?? null;
			$bid->end_date = $extracted['end_date'] ?? null;
			$bid->naics_code = $extracted['naics_code'] ?? null;
			$bid->other_data = $otherData ?: null;
			$bid->raw_html = $rawHtmlToStore; // May be null if saved to disk
			$bid->extracted_json = $extracted;
			$bid->save();

			$message = 'Bid scraped successfully!';
			if (isset($extracted['_warning'])) {
				$message .= ' Note: ' . $extracted['_warning'];
			}

			return redirect()->route('bids.show', $bid)->with('success', $message);
		} catch (\Throwable $e) {
			Log::error('Scrape failed', ['error' => $e->getMessage()]);
			return back()->withErrors(['url' => 'Failed to scrape this URL. ' . $e->getMessage()])->withInput();
		}
	}

	public function show(Bid $bid)
	{
		return view('bids.show', compact('bid'));
	}
}
