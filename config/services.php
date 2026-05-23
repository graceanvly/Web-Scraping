<?php

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
        'http_timeout' => max(30.0, (float) env('OPENAI_HTTP_TIMEOUT', 300)),
        // Title rewrite payloads are smaller; fail faster if the API stalls.
        'http_timeout_rewrite' => max(30.0, (float) env('OPENAI_HTTP_TIMEOUT_REWRITE', 120)),
        'http_connect_timeout' => max(5.0, (float) env('OPENAI_HTTP_CONNECT_TIMEOUT', 30)),
    ],

];
