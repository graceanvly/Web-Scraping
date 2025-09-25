<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Spatie\Browsershot\Browsershot;

class ScraperService
{
    private Client $httpClient;

	public function __construct()
	{
		$this->httpClient = new Client([
			'timeout' => 60,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
				'Accept-Encoding' => 'gzip, deflate, br',
				'Connection' => 'keep-alive',
				'Upgrade-Insecure-Requests' => '1',
				'Sec-Fetch-Dest' => 'document',
				'Sec-Fetch-Mode' => 'navigate',
				'Sec-Fetch-Site' => 'none',
				'Sec-Fetch-User' => '?1',
				'Cache-Control' => 'max-age=0',
			],
			'allow_redirects' => [
				'max' => 5,
				'strict' => false,
				'referer' => true,
				'protocols' => ['http', 'https'],
			],
			'verify' => false,
			'cookies' => true,
		]);
	}

    public function fetch(string $url): array
    {
        $attempts = [
            // First attempt: Standard request
            ['headers' => []],
            // Second attempt: With referer from Google
            ['headers' => [
                'Referer' => 'https://www.google.com/',
                'Sec-Fetch-Site' => 'cross-site',
            ]],
        ];

        $lastResponse = null;
        $bestText = '';
        $bestHtml = '';

        foreach ($attempts as $i => $config) {
            try {
                // Avoid sleeping to reduce total execution time
                
                $response = $this->httpClient->get($url, $config);
                $html = (string) $response->getBody();
                $text = $this->htmlToText($html);
                
                // Check if this attempt got better content
                if (strlen($text) > strlen($bestText) && 
                    strpos($text, 'Client Challenge') === false && 
                    strpos($text, 'bot') === false) {
                    $bestText = $text;
                    $bestHtml = $html;
                    $lastResponse = $response;
                }
                
                // If we got good content, break early
                if (strlen($text) > 1200 && strpos($text, 'Client Challenge') === false) {
                    break;
                }
                
            } catch (RequestException $e) {
                // Continue to next attempt
                continue;
            }
        }

        // If HTTP attempts failed or content looks blocked, try headless browser render
        $shouldUseBrowser = (!$lastResponse) || (strlen($bestText) < 800);
        if ($shouldUseBrowser) {
            try {
                $html = Browsershot::url($url)
                    ->setNodeBinary('node')
                    ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                    ->setExtraHttpHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Referer' => 'https://www.google.com/',
                    ])
                    ->disableSandbox()
                    // Keep overall timeout strict and avoid waiting indefinitely on network
                    ->timeout(90)
                    // Let the DOM load quickly, then give it a short fixed delay
                    ->setDelay(1500)
                    ->bodyHtml();

                $text = $this->htmlToText($html);
                if (strlen($text) > strlen($bestText)) {
                    $bestHtml = $html;
                    $bestText = $text;
                }
            } catch (\Throwable $e) {
                // Headless render failed; continue with best we have
            }
        }

        if (!$lastResponse && empty($bestHtml)) {
            throw new \Exception('All scraping attempts failed');
        }

        $finalUrl = $lastResponse ? ((string) $lastResponse->getHeaderLine('X-Guzzle-Redirect-History') ?: $url) : $url;

        return [
            'final_url' => $finalUrl,
            'html' => $bestHtml ?: ($lastResponse ? (string) $lastResponse->getBody() : ''),
            'text' => $bestText ?: ($lastResponse ? $this->htmlToText((string) $lastResponse->getBody()) : ''),
        ];
    }

    private function htmlToText(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML($html);

        if (!$loaded) {
            return strip_tags($html);
        }

        // Remove unwanted tags
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $text = $dom->textContent ?? '';
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }
}

