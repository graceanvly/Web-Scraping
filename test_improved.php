<?php

require_once 'vendor/autoload.php';

// Load .env file
if (file_exists('.env')) {
    $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value, '"\'');
        }
    }
}

use App\Services\ScraperService;
use App\Services\AIExtractor;

$scraper = new ScraperService();
$ai = new AIExtractor();

$url = 'https://www.mcs4kids.com/o/mcs/page/open-bids-and-proposals';
echo "Fetching URL: $url\n";

try {
    $result = $scraper->fetch($url);
    echo "HTML length: " . strlen($result['html']) . "\n";
    echo "Text length: " . strlen($result['text']) . "\n";
    echo "First 1000 chars of text:\n";
    echo substr($result['text'], 0, 1000) . "\n";
    echo "\n---\n";
    
    $extracted = $ai->extract($url, $result['html'], $result['text']);
    echo "Extracted data:\n";
    print_r($extracted);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
