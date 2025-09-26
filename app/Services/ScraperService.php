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
                'Connection' => 'keep-alive',
            ],
            'allow_redirects' => [
                'max' => 5,
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
            ['headers' => []],
            ['headers' => [
                'Referer' => 'https://www.google.com/',
                'Sec-Fetch-Site' => 'cross-site',
            ]],
        ];

        $bestText = '';
        $bestHtml = '';
        $finalUrl = $url;

        foreach ($attempts as $config) {
            try {
                $response = $this->httpClient->get($url, $config);
                $html = (string) $response->getBody();
                $text = $this->htmlToText($html);

                if ($this->isBetterContent($text, $bestText)) {
                    $bestText = $text;
                    $bestHtml = $html;
                    $finalUrl = (string) $response->getHeaderLine('X-Guzzle-Redirect-History') ?: $url;
                }

                if (strlen($text) > 1500 && !str_contains($text, 'Client Challenge')) {
                    break;
                }
            } catch (RequestException $e) {
                continue;
            }
        }

        // Fallback to headless browser
        if (strlen($bestText) < 1000) {
            try {
                $html = Browsershot::url($url)
                    ->setNodeBinary('node')
                    ->timeout(120)
                    ->setDelay(2000)
                    ->disableSandbox()
                    ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
                    ->bodyHtml();

                $text = $this->htmlToText($html);

                if ($this->isBetterContent($text, $bestText)) {
                    $bestHtml = $html;
                    $bestText = $text;
                }
            } catch (\Throwable $e) {
                // Return what we have, mark as blocked
                return [
                    'final_url' => $finalUrl,
                    'html' => $bestHtml,
                    'text' => $bestText,
                    'blocked' => true,
                ];
            }
        }

        return [
            'final_url' => $finalUrl,
            'html' => $bestHtml,
            'text' => $bestText,
            'blocked' => false,
        ];
    }

    private function htmlToText(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadHTML($html)) {
            return strip_tags($html);
        }

        $xpath = new \DOMXPath($dom);

        // Drop scripts, styles, navs, and footers
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $text = $dom->textContent ?? '';
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function isBetterContent(string $new, string $current): bool
    {
        return strlen($new) > strlen($current) &&
            !str_contains(strtolower($new), 'client challenge') &&
            !str_contains(strtolower($new), 'captcha');
    }
}
