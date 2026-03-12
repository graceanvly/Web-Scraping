<?php

namespace App\Http\Controllers;

use App\Models\BidUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

            if (BidUrl::where('url', $urlValue)
                ->orWhere('name', $nameValue)
                ->exists()) {
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
    public function index()
    {
        $perPage = (int) request('per_page', 50);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $bidUrls = BidUrl::paginate($perPage)->withQueryString();
        return view('bidurl.index', compact('bidUrls'));
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
                Rule::unique('bid_url', 'url'),
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('bid_url', 'name'),
            ],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

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
                Rule::unique('bid_url', 'url')->ignore($bidUrl->id),
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('bid_url', 'name')->ignore($bidUrl->id),
            ],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

        $bidUrl->update($data);

        return redirect()->route('bidurl.index')->with('success', 'Bid URL updated.');
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

    
}
