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

			if (empty($saved)) {
				return back()->withErrors(['url' => 'No open bids found on this page.']);
			}

			return redirect()->route('bids.index')
				->with('success', count($saved) . ' bids scraped and saved successfully!');
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
}
