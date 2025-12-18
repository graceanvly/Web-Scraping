<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Spatie\Browsershot\Browsershot;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Log;

class ScraperService
{
	private Client $httpClient;
	private int $maxPdfBytes = 15728640; // 15 MB limit per PDF to avoid timeouts on large files.
	private int $pdfTimeout = 30; // Seconds per PDF download.
	private int $maxPdfDownloads = 8; // Avoid excessive parallel PDF processing per page.
	private int $maxPerUrlSeconds = 200; // Hard cap per URL scrape to avoid controller timeouts.
	private int $requestTimeout = 60; // General HTTP request timeout.

	// app/Services/ScraperService.php
	public function __construct()
	{
		$headers = [
			'User-Agent' => 'Mozilla/5.0 ...',
			'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Connection' => 'keep-alive',
		];
		$cookieHeader = trim((string) env('SCRAPER_COOKIE', ''));
		if ($cookieHeader !== '') {
			$headers['Cookie'] = $cookieHeader;
		}

		$this->httpClient = new Client([
			'timeout' => 90,
			'connect_timeout' => 20,
			'headers' => $headers,
			'allow_redirects' => [
				'max' => 5,
				'referer' => true,
				'protocols' => ['http', 'https'],
			],
			'verify' => false,
			'cookies' => true,
			// force IPv4 + TLS1.2 to avoid handshake issues
			'curl' => [
				CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
				CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
			],
		]);
	}


	public function fetch(string $url, ?string $username = null, ?string $password = null): array
	{
		$startedAt = microtime(true);
		$pdfText = '';
		$bidPages = [];
		$noOpenBids = false;
		$blocked = false;
		$blockedReason = null;
		$authProvided = $this->hasAuthCredentials($username, $password);
		$requestOptions = $this->buildRequestOptions($username, $password);

		// 1. If direct PDF URL
		if (preg_match('/\.pdf($|\?)/i', $url)) {
			return $this->fetchPdf($url, $requestOptions, $authProvided, $startedAt);
		}

		// 2. Fetch HTML content
		$bestHtml = '';
		$bestText = '';
		$finalUrl = $url;

		try {
			$this->ensureWithinBudget($startedAt, 'initial page request');
			$response = $this->requestWithAuth($url, $requestOptions, $authProvided);
		} catch (RequestException $e) {
			Log::warning('SCRAPER FETCH ERROR', ['url' => $url, 'error' => $e->getMessage()]);
			if (str_starts_with($url, 'https://')) {
				$httpUrl = 'http://' . substr($url, 8);
				try {
					$this->ensureWithinBudget($startedAt, 'http fallback request');
					$response = $this->requestWithAuth($httpUrl, $requestOptions, $authProvided);
					$url = $httpUrl;
				} catch (RequestException $e2) {
					throw $e2;
				}
			} else {
				throw $e;
			}
		}
		$bestHtml = (string) ($response->getBody() ?? '');
		$bestText = $this->htmlToText($bestHtml);
		$blockedReason = $this->detectBlockedPage($bestHtml, $bestText);
		$blocked = !empty($blockedReason);
		$this->guardLoginRequirement($bestHtml, $bestText, $authProvided);
		$noOpenBids = $this->detectNoOpenBids($bestHtml, $bestText);
		if ($noOpenBids) {
			Log::info('NO OPEN BIDS FLAGGED', ['url' => $url]);
		}

		// 3. Find PDF links (bids)
		$pdfBids = $noOpenBids ? [] : $this->findPdfLink($bestHtml, $url);

		if (!empty($pdfBids)) {
			// Cap PDF downloads to avoid long-running requests.
			if (count($pdfBids) > $this->maxPdfDownloads) {
				Log::info('PDF DOWNLOADS CAPPED', ['url' => $url, 'found' => count($pdfBids), 'capped_at' => $this->maxPdfDownloads]);
				$pdfBids = array_slice($pdfBids, 0, $this->maxPdfDownloads);
			}

			Log::info('PDF LINKS FOUND', ['url' => $url, 'count' => count($pdfBids)]);

			$pdfTexts = [];
			foreach ($pdfBids as $bid) {
				if (!empty($bid['PDF_LINK'])) {
					$this->ensureWithinBudget($startedAt, 'pdf download loop');
					$pdfResult = $this->fetchPdf($bid['PDF_LINK'], $requestOptions, $authProvided, $startedAt);
					if (!empty($pdfResult['text'])) {
						$pdfTexts[] = $pdfResult['text'];
					}
				}
			}
			$pdfText = implode("\n\n----- NEXT DOCUMENT -----\n\n", $pdfTexts);
		} else {
			Log::info('NO PDF LINK FOUND', ['url' => $url]);
		}

		// 3b. Follow clickable bid titles (detail pages) to pull richer data and PDFs
		$detailLinks = $noOpenBids ? [] : $this->findBidDetailLinks($bestHtml, $url);
		$detailLinks = array_slice($detailLinks, 0, 8); // cap depth
		foreach ($detailLinks as $detail) {
			try {
				$pageHtml = '';
				$pageText = '';
				$pagePdfText = '';
				$pagePdfLinks = [];

				$this->ensureWithinBudget($startedAt, 'detail page request');
				$response = $this->requestWithAuth($detail['URL'], $requestOptions, $authProvided);
				$pageHtml = (string) $response->getBody();
				$pageText = $this->htmlToText($pageHtml);
				$this->guardLoginRequirement($pageHtml, $pageText, $authProvided);
				$pagePdfLinks = $this->findPdfLink($pageHtml, $detail['URL']);

				$pdfTexts = [];
				foreach ($pagePdfLinks as $link) {
					if (!empty($link['PDF_LINK'])) {
						$this->ensureWithinBudget($startedAt, 'detail pdf loop');
						$pdfResult = $this->fetchPdf($link['PDF_LINK'], $requestOptions, $authProvided, $startedAt);
						if (!empty($pdfResult['text'])) {
							$pdfTexts[] = $pdfResult['text'];
						}
					}
				}
				$pagePdfText = implode("\n\n----- NEXT DOCUMENT -----\n\n", $pdfTexts);

				if (!empty($pagePdfText)) {
					$pdfText .= (!empty($pdfText) ? "\n\n----- NEXT DOCUMENT -----\n\n" : '') . $pagePdfText;
				}

				$bidPages[] = [
					'title' => $detail['TITLE'],
					'url' => $detail['URL'],
					'html' => $pageHtml,
					'text' => $pageText,
					'pdf_links' => $pagePdfLinks,
					'pdf_text' => $pagePdfText,
				];
			} catch (\Throwable $e) {
				Log::warning('CLICKED BID PAGE FAILED', ['url' => $detail['URL'], 'error' => $e->getMessage()]);
			}
		}

		// 4. Fallback with Browsershot if page text is too short
		if (strlen($bestText) < 500) {
			try {
				$this->ensureWithinBudget($startedAt, 'browsershot fetch');
				$html = Browsershot::url($url)
					->setNodeBinary('node')
					->timeout(120)
					->setDelay(2000)
					->disableSandbox()
					->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
					->bodyHtml();

				$text = $this->htmlToText($html);

				if (strlen($text) > strlen($bestText)) {
					$bestHtml = $html;
					$bestText = $text;
					$this->guardLoginRequirement($bestHtml, $bestText, $authProvided);
				}
			} catch (\Throwable $e) {
				Log::warning('BROWSERSHOT FAILED', ['url' => $url, 'error' => $e->getMessage()]);
			}
		}

		Log::info('SCRAPER DEBUG', [
			'url' => $url,
			'pdf_found' => !empty($pdfBids),
			'pdf_count' => count($pdfBids),
			'pdf_length' => strlen($pdfText),
			'pdf_preview' => substr($pdfText, 0, 100),
			'clicked_bid_pages' => count($bidPages),
		]);

		return [
			'final_url' => $finalUrl,
			'html' => $bestHtml,
			'text' => $bestText,
			'pdf_bids' => $pdfBids,
			'pdf_text' => $pdfText,
			'bid_pages' => $bidPages,
			'blocked' => $blocked,
			'blocked_reason' => $blockedReason,
			'no_open_bids' => $noOpenBids,
		];
	}

	private function buildRequestOptions(?string $username, ?string $password): array
	{
		$options = [];
		if ($this->hasAuthCredentials($username, $password)) {
			$options['auth'] = [$username, $password];
		}

		return $options;
	}

	private function hasAuthCredentials(?string $username, ?string $password): bool
	{
		return !empty($username) && !empty($password);
	}

	private function requestWithAuth(string $url, array $options, bool $authProvided)
	{
		try {
			// Apply sane defaults if not provided.
			if (!isset($options['timeout'])) {
				$options['timeout'] = $this->requestTimeout;
			}
			if (!isset($options['read_timeout'])) {
				$options['read_timeout'] = $this->requestTimeout;
			}
			if (!isset($options['connect_timeout'])) {
				$options['connect_timeout'] = 20;
			}

			return $this->httpClient->get($url, $options);
		} catch (RequestException $e) {
			// If credentials were supplied, allow parsing the response body even on 4xx.
			if ($authProvided && $e->getResponse()) {
				Log::warning('AUTH REQUEST RETURNED ERROR STATUS, USING BODY', [
					'url' => $url,
					'status' => $e->getResponse()->getStatusCode(),
				]);
				return $e->getResponse();
			}

			$status = $e->getResponse()?->getStatusCode();
			if ($status === 401) {
				if ($authProvided) {
					throw new \RuntimeException('Provided login credentials were not accepted for this URL.');
				}
				throw new \RuntimeException('Login credentials are required to scrape this URL. Please add a username and password.');
			}

			throw $e;
		}
	}

	private function guardLoginRequirement(string $html, string $text, bool $authProvided): void
	{
		if (!$this->looksLikeLoginPage($html, $text)) {
			return;
		}

		if ($authProvided) {
			throw new \RuntimeException('Provided login credentials did not allow access to the bid details for this URL.');
		}

		throw new \RuntimeException('Login credentials are required to scrape this URL. Please add a username and password.');
	}

	private function looksLikeLoginPage(string $html, string $text): bool
	{
		$haystack = strtolower($text . ' ' . strip_tags($html));

		// Quick checks for login form markers.
		if (preg_match('/type=[\"\\\']password[\"\\\']/i', $html) && preg_match('/log in|login|sign in/i', $haystack)) {
			return true;
		}

		if (preg_match('/<form[^>]*(login|signin)[^>]*>/i', $html)) {
			return true;
		}

		return false;
	}

	private function isAuthError(?int $status): bool
	{
		return in_array($status, [401, 403], true);
	}

	private function formatBytes(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$i = 0;
		$val = $bytes;
		while ($val >= 1024 && $i < count($units) - 1) {
			$val /= 1024;
			$i++;
		}

		return round($val, 1) . ' ' . $units[$i];
	}

	private function ensureWithinBudget(float $startedAt, string $context): void
	{
		$elapsed = microtime(true) - $startedAt;
		if ($elapsed > $this->maxPerUrlSeconds) {
			throw new \RuntimeException("Scrape timed out while processing {$context}. Please retry later or reduce the amount of content to download.");
		}
	}

	private function findPdfLink(string $html, string $baseUrl): array
	{
		if (empty($html))
			return [];

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML($html);
		$xpath = new \DOMXPath($dom);

		$bids = [];

		foreach ($xpath->query('//a[@href] | //iframe[@src] | //embed[@src]') as $node) {
			$attr = $node->getAttribute('href') ?: $node->getAttribute('src');
			if (preg_match('/\.pdf($|\?)/i', $attr)) {
				$pdfUrl = $this->resolveUrl($attr, $baseUrl);
				$bids[] = [
					'TITLE' => $this->extractTitleFromUrl($pdfUrl),
					'PDF_LINK' => $pdfUrl,
				];
			}
		}

		// fallback: detect raw .pdf URLs in text
		if (empty($bids) && preg_match_all('/https?:\/\/[^\\s"\']+\.pdf/i', $html, $matches)) {
			foreach ($matches[0] as $pdfUrl) {
				$bids[] = [
					'TITLE' => $this->extractTitleFromUrl($pdfUrl),
					'PDF_LINK' => $pdfUrl,
				];
			}
		}

		return $bids;
	}

	private function extractTitleFromUrl(string $URL): string
	{
		$path = parse_url($URL, PHP_URL_PATH);
		$segments = array_values(array_filter(explode('/', trim((string) $path, '/'))));
		$descriptiveSegments = array_filter($segments, fn($seg) => strlen($seg) > 3 && !is_numeric($seg));

		$title = !empty($descriptiveSegments) ? end($descriptiveSegments) : ($segments[count($segments) - 1] ?? '');
		$title = str_replace(['-', '_', '%20'], ' ', $title);
		$title = urldecode($title);
		$title = trim(ucwords(strtolower($title)));

		return $title ?: 'Document';
	}

	/**
	 * Attempt to find clickable bid titles (non-PDF detail links) to crawl for richer data.
	 */
	private function findBidDetailLinks(string $html, string $baseUrl): array
	{
		if (empty($html))
			return [];

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML($html);
		$xpath = new \DOMXPath($dom);

		$links = [];
		foreach ($xpath->query('//a[@href]') as $anchor) {
			$text = trim($anchor->textContent ?? '');
			$href = $anchor->getAttribute('href');

			if (strlen($text) < 5)
				continue;
			if (preg_match('/\.pdf($|\?)/i', $href))
				continue;
			if (stripos($href, 'javascript:') === 0)
				continue;

			$resolved = $this->resolveUrl($href, $baseUrl);
			$lower = strtolower($text . ' ' . $resolved);
			if (!preg_match('/bid|rfp|rfq|tender|solicitation|proposal/', $lower)) {
				continue;
			}

			$links[] = [
				'TITLE' => trim($text),
				'URL' => $resolved,
			];
		}

		// Deduplicate by URL
		$unique = [];
		$deduped = [];
		foreach ($links as $link) {
			if (isset($unique[$link['URL']]))
				continue;
			$unique[$link['URL']] = true;
			$deduped[] = $link;
		}

		return $deduped;
	}

	private function fetchPdf(string $url, array $requestOptions, bool $authProvided, float $startedAt): array
	{
		try {
			$this->ensureWithinBudget($startedAt, 'pdf download');
			$options = array_merge($requestOptions, [
				'stream' => true,
				'timeout' => $this->pdfTimeout,
				'read_timeout' => $this->pdfTimeout,
				'connect_timeout' => 20,
			]);

			$response = $this->requestWithAuth($url, $options, $authProvided);

			// Enforce size limit using headers if available.
			$lenHeader = $response->getHeaderLine('Content-Length');
			if (!empty($lenHeader) && (int) $lenHeader > $this->maxPdfBytes) {
				throw new \RuntimeException("PDF is larger than allowed (" . $this->formatBytes($this->maxPdfBytes) . ").");
			}

			$tempFile = tmpfile();
			$meta = stream_get_meta_data($tempFile);
			$body = $response->getBody();
			$downloaded = 0;

			while (!$body->eof()) {
				$chunk = $body->read(8192);
				$downloaded += strlen($chunk);
				if ($downloaded > $this->maxPdfBytes) {
					throw new \RuntimeException("PDF exceeds size limit (" . $this->formatBytes($this->maxPdfBytes) . ").");
				}
				fwrite($tempFile, $chunk);
			}

			$parser = new Parser();
			$pdf = $parser->parseFile($meta['uri']);
			$text = trim($pdf->getText());

			if (empty($text) && function_exists('shell_exec')) {
				$cmd = "pdftotext " . escapeshellarg($meta['uri']) . " -";
				$text = shell_exec($cmd) ?: '';
			}

			fclose($tempFile);

			return [
				'final_url' => $url,
				'text' => $text,
				'is_pdf' => true,
			];
		} catch (\Throwable $e) {
			Log::error('PDF FETCH ERROR', ['url' => $url, 'error' => $e->getMessage()]);
			return [
				'final_url' => $url,
				'text' => '',
				'is_pdf' => true,
				'error' => $e->getMessage(),
			];
		}
	}

	private function htmlToText(string $html): string
	{
		libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		if (!$dom->loadHTML($html)) {
			return strip_tags($html);
		}

		$xpath = new \DOMXPath($dom);
		foreach ($xpath->query('//script|//style|//noscript|//nav|//footer') as $node) {
			$node->parentNode?->removeChild($node);
		}

		$text = $dom->textContent ?? '';
		$text = preg_replace('/\s+/', ' ', $text);
		return trim($text);
	}

	private function resolveUrl(string $href, string $base): string
	{
		if (preg_match('/^https?:/i', $href)) {
			return $href;
		}

		$baseParts = parse_url($base);
		$scheme = $baseParts['scheme'] ?? 'https';
		$host = $baseParts['host'] ?? '';
		$prefix = $scheme . '://' . $host;

		if (str_starts_with($href, '/')) {
			return $prefix . $href;
		}
		return rtrim($prefix, '/') . '/' . ltrim($href, '/');
	}

	private function detectBlockedPage(string $html, string $text): ?string
	{
		$haystack = strtolower($text . ' ' . strip_tags($html));
		$signals = [
			'blocked country',
			'sorry, you have been blocked',
			'unable to access',
			'cloudflare',
			'geolocation setting',
			'connection was denied because this country',
			'watchguard',
		];

		foreach ($signals as $signal) {
			if (str_contains($haystack, $signal)) {
				return $signal;
			}
		}

		return null;
	}

	private function detectNoOpenBids(string $html, string $text): bool
	{
		$haystack = strtolower($text . ' ' . strip_tags($html));
		$patterns = [
			'no open bid',
			'no open bids',
			'no open solicitations',
			'no open opportunities',
			'no bid postings',
			'there are no bids',
			'no current bids',
			'no current solicitations',
			'no open procurement',
			'no opportunities available',
			'there are no open bid postings',
		];

		foreach ($patterns as $pattern) {
			if (str_contains($haystack, $pattern)) {
				return true;
			}
		}

		return false;
	}
}
