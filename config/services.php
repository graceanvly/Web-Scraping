<?php

$openAiExtractTimeout = max(30.0, (float) env('OPENAI_HTTP_TIMEOUT', 300));
// OpenAI: read here (not via env() in services) so php artisan config:cache works. Default stream avoids
// Guzzle CurlHandler + libcurl "curl_setopt_array … invalid cURL options" on some PHP builds.
$openAiHttpHandler = strtolower(trim((string) env('OPENAI_HTTP_HANDLER', 'stream')));

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        // Full extract() calls can include large listing + PDF excerpts; keep above OpenAI’s typical latency.
        'http_timeout' => $openAiExtractTimeout,
        // Optional tighter cap for bulk_mode extract (defaults to OPENAI_HTTP_TIMEOUT when unset).
        'http_timeout_bulk_extract' => max(30.0, (float) env('OPENAI_HTTP_TIMEOUT_BULK', $openAiExtractTimeout)),
        // Title rewrite payloads are smaller; fail faster if the API stalls.
        'http_timeout_rewrite' => max(30.0, (float) env('OPENAI_HTTP_TIMEOUT_REWRITE', 120)),
        'http_connect_timeout' => max(5.0, (float) env('OPENAI_HTTP_CONNECT_TIMEOUT', 30)),
        /** Guzzle StreamHandler when true; set OPENAI_HTTP_HANDLER=curl to force CurlHandler. */
        'http_use_stream' => $openAiHttpHandler !== 'curl',
        'extract_heartbeat' => filter_var(env('OPENAI_EXTRACT_HEARTBEAT', false), FILTER_VALIDATE_BOOL),
        'extract_heartbeat_sec' => max(15, min(120, (int) env('OPENAI_EXTRACT_HEARTBEAT_SEC', 22))),
    ],

];
