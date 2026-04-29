<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $latestErrorLogs = DB::table('scrape_logs')
            ->where('level', 'error')
            ->whereNotNull('bid_url_id')
            ->orderByDesc('created_at')
            ->get()
            ->unique('bid_url_id')
            ->keyBy('bid_url_id');

        if ($latestErrorLogs->isEmpty()) {
            return;
        }

        $bidUrls = DB::table('bid_url')
            ->whereIn('id', $latestErrorLogs->keys()->all())
            ->get();

        foreach ($bidUrls as $bidUrl) {
            $latestError = $latestErrorLogs->get($bidUrl->id);
            if (!$latestError) {
                continue;
            }

            $shouldMove = !$bidUrl->last_scraped_at || strtotime((string) $latestError->created_at) > strtotime((string) $bidUrl->last_scraped_at);
            if (!$shouldMove) {
                continue;
            }

            $existingFailed = DB::table('failed_bid_urls')->where('url', $bidUrl->url)->first();

            $payload = [
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
                'username' => $bidUrl->username ?? null,
                'password' => $bidUrl->password ?? null,
                'last_scraped_at' => $bidUrl->last_scraped_at,
                'failure_message' => $latestError->message,
                'failed_at' => $latestError->created_at ?? now(),
            ];

            if ($existingFailed) {
                DB::table('failed_bid_urls')
                    ->where('id', $existingFailed->id)
                    ->update($payload);
            } else {
                DB::table('failed_bid_urls')->insert($payload);
            }

            DB::table('bid_url')->where('id', $bidUrl->id)->delete();
        }
    }

    public function down(): void
    {
        // No automatic rollback for moved records.
    }
};
