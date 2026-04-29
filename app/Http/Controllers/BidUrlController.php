<?php

namespace App\Http\Controllers;

use App\Models\BidUrl;
use App\Models\FailedBidUrl;
use Illuminate\Http\Request;

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

            if ($this->urlOrNameExists($urlValue, $nameValue)) {
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
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 50);
        if ($perPage < 5) {
            $perPage = 5;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $search = trim((string) $request->query('search', ''));

        $query = BidUrl::query()->orderBy('id');
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

        return view('bidurl.index', [
            'bidUrls' => $bidUrls,
            'failedBidUrls' => $failedBidUrls,
            'search' => $search,
            'failedCount' => $failedBidUrls->total(),
        ]);
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
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

        $this->ensureUrlAndNameAreAvailable($data['url'], $data['name'] ?? null);

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
        ], [
            'url.required' => 'Please provide a URL.',
            'url.url' => 'Enter a valid URL (http or https).',
            'url.regex' => 'Only http:// or https:// links are supported.',
            'url.unique' => 'This URL is already in the list.',
            'name.unique' => 'This name is already in the list.',
        ]);

        $this->ensureUrlAndNameAreAvailable($data['url'], $data['name'] ?? null, $bidUrl->id);

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

    public function restoreFailed(FailedBidUrl $failedBidUrl)
    {
        $this->ensureUrlAndNameAreAvailable($failedBidUrl->url, $failedBidUrl->name, null, $failedBidUrl->id);

        BidUrl::create([
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
            'last_scraped_at' => $failedBidUrl->last_scraped_at,
        ]);

        $failedBidUrl->delete();

        return redirect()->route('bidurl.index')->with('success', 'Failed URL restored to Bid URLs.');
    }

    public function destroyFailed(FailedBidUrl $failedBidUrl)
    {
        $failedBidUrl->delete();

        return redirect()->route('bidurl.index')->with('success', 'Failed URL deleted.');
    }

    private function ensureUrlAndNameAreAvailable(string $url, ?string $name = null, ?int $ignoreBidUrlId = null, ?int $ignoreFailedBidUrlId = null): void
    {
        if ($this->urlExists($url, $ignoreBidUrlId, $ignoreFailedBidUrlId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'This URL already exists in the active or failed URL list.',
            ]);
        }

        if ($name !== null && $name !== '') {
            if ($this->nameExists($name, $ignoreBidUrlId, $ignoreFailedBidUrlId)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'name' => 'This name already exists in the active or failed URL list.',
                ]);
            }
        }
    }

    private function urlOrNameExists(string $url, ?string $name = null): bool
    {
        return $this->urlExists($url) || ($name !== null && $name !== '' && $this->nameExists($name));
    }

    private function urlExists(string $url, ?int $ignoreBidUrlId = null, ?int $ignoreFailedBidUrlId = null): bool
    {
        $activeUrlQuery = BidUrl::where('url', $url);
        if ($ignoreBidUrlId) {
            $activeUrlQuery->where('id', '!=', $ignoreBidUrlId);
        }

        $failedUrlQuery = FailedBidUrl::where('url', $url);
        if ($ignoreFailedBidUrlId) {
            $failedUrlQuery->where('id', '!=', $ignoreFailedBidUrlId);
        }

        return $activeUrlQuery->exists() || $failedUrlQuery->exists();
    }

    private function nameExists(string $name, ?int $ignoreBidUrlId = null, ?int $ignoreFailedBidUrlId = null): bool
    {
        $activeNameQuery = BidUrl::where('name', $name);
        if ($ignoreBidUrlId) {
            $activeNameQuery->where('id', '!=', $ignoreBidUrlId);
        }

        $failedNameQuery = FailedBidUrl::where('name', $name);
        if ($ignoreFailedBidUrlId) {
            $failedNameQuery->where('id', '!=', $ignoreFailedBidUrlId);
        }

        return $activeNameQuery->exists() || $failedNameQuery->exists();
    }
}
