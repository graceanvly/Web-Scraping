<?php

namespace App\Http\Controllers;

use App\Models\BidUrl;
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

            BidUrl::create([
                'url' => trim($fields[0]),
                'name' => trim($fields[1]),
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
        $bidUrls = BidUrl::latest()->paginate(15);
        return view('bidurl.index', compact('bidUrls'));
    }

    /**
     * Show a single BidUrl record.
     */
    public function show(BidUrl $bidUrl)
    {
        return view('bidurl.show', compact('bidUrl'));
    }

    
}
