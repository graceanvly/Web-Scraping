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
		$bids = Bid::latest('CREATED')->limit(10)->get();
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
			// 1️⃣ Fetch page data and find PDF link
			$result = $scraper->fetch($validated['URL']);
			Log::info('SCRAPER DEBUG', [
				'url' => $validated['URL'],
				'pdf_link' => $result['pdf_link'] ?? null,
				'text_length' => strlen($result['text'] ?? ''),
			]);

			// 2️⃣ Let AI extract relevant bid data from the HTML/text
			$extracted = $ai->extract($validated['URL'], $result['html'], $result['text']);

			$today = \Carbon\Carbon::today();
			$filteredBids = collect($extracted['bids'] ?? [])->filter(function ($bid) use ($today) {
				if (!empty($bid['ENDDATE'])) {
					try {
						$end = \Carbon\Carbon::parse($bid['ENDDATE']);
						if ($end->lt($today))
							return false;
					} catch (\Exception $e) {
						return false;
					}
				}
				return true;
			})->values();

			$saved = [];
			$duplicates = [];

			// 3️⃣ Save bids
			foreach ($filteredBids as $bidData) {
				$title = $bidData['TITLE'] ?? null;
				if (!$title)
					continue;

				$exists = Bid::where('URL', $validated['URL'])
					->where('TITLE', $title)
					->exists();

				if ($exists) {
					$duplicates[] = $title;
					continue;
				}

				$bid = new Bid();
				$bid->URL = $validated['URL'];
				$bid->TITLE = $title;
				$bid->ENDDATE = $bidData['ENDDATE'] ?? null;
				$bid->NAICSCODE = $bidData['NAICSCODE'] ?? null;

				// 🧩 Save the PDF link instead of its content
				$bid->DESCRIPTION = $result['pdf_link']
					?? ($bidData['DESCRIPTION'] ?? 'No description or PDF link found.');

				$bid->CREATED = now();
				$bid->LAST_MODIFIED = now();
				$bid->save();

				$saved[] = $bid->id;
			}

			// 4️⃣ Response
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
			if ($saved)
				$msg .= count($saved) . ' bid(s) saved. ';
			if ($duplicates)
				$msg .= count($duplicates) . ' duplicate bid(s) skipped.';

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
		// Allow longer execution time for multiple URLs
		@set_time_limit(300);
		@ini_set('max_execution_time', '300');

		$bidUrls = BidUrl::all();
		$scraped = 0;
		$duplicates = 0;
		$errors = [];

		foreach ($bidUrls as $bidUrl) {
			try {
				$result = $scraper->fetch($bidUrl->URL);
				$extracted = $ai->extract($bidUrl->URL, $result['html'], $result['text']);

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
				})->values()->all();

				foreach ($filteredBids as $bidData) {
					$exists = Bid::where('URL', $bidUrl->URL)
						->where('TITLE', $bidData['TITLE'] ?? null)
						->exists();

					if ($exists) {
						$duplicates++;
						continue; // Skip duplicates
					}

					$bid = new Bid();
					$bid->URL = $bidUrl->URL;
					$bid->TITLE = $bidData['TITLE'] ?? null;
					$bid->ENDDATE = !empty($bidData['ENDDATE']) ? $bidData['ENDDATE'] : null;
					$bid->NAICSCODE = $bidData['NAICSCODE'] ?? null;
					$bid->save();

					$scraped++;
				}
			} catch (\Throwable $e) {
				Log::error('Scrape failed for URL: ' . $bidUrl->URL, [
					'error' => $e->getMessage()
				]);
				$errors[] = $bidUrl->URL;
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

}
