<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
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
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Accept-Encoding' => 'gzip, deflate, br',
			'Connection' => 'keep-alive',
			'Upgrade-Insecure-Requests' => '1',
			'Sec-Fetch-Dest' => 'document',
			'Sec-Fetch-Mode' => 'navigate',
			'Sec-Fetch-Site' => 'none',
			'Sec-Fetch-User' => '?1',
			'Sec-Ch-Ua' => '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
			'Sec-Ch-Ua-Mobile' => '?0',
			'Sec-Ch-Ua-Platform' => '"Windows"',
			'Cache-Control' => 'max-age=0',
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
			'decode_content' => true,
			'curl' => [
				CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
				CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
				CURLOPT_ENCODING => '',
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

		$httpFailed = false;
		$httpFailReason = '';

		try {
			$this->ensureWithinBudget($startedAt, 'initial page request');
			$shortOpts = array_merge($requestOptions, ['timeout' => 30, 'connect_timeout' => 15]);
			$response = $this->requestWithAuth($url, $shortOpts, $authProvided);
			$bestHtml = (string) ($response->getBody() ?? '');
			$bestText = $this->htmlToText($bestHtml);
		} catch (ConnectException $e) {
			Log::warning('SCRAPER CONNECT/TIMEOUT ERROR — falling back to headless Chrome', ['url' => $url, 'error' => $e->getMessage()]);
			$httpFailed = true;
			$httpFailReason = 'Connection timed out';
		} catch (RequestException $e) {
			$httpStatus = $e->getResponse()?->getStatusCode();
			Log::warning('SCRAPER FETCH ERROR', ['url' => $url, 'status' => $httpStatus, 'error' => $e->getMessage()]);

			$isTimeout = $httpStatus === null && (
				stripos($e->getMessage(), 'timed out') !== false ||
				stripos($e->getMessage(), 'timeout') !== false ||
				stripos($e->getMessage(), 'cURL error 28') !== false
			);

			if ($httpStatus === 403 || $isTimeout) {
				$httpFailed = true;
				$httpFailReason = $httpStatus === 403 ? '403 Forbidden' : 'Request timed out';
				Log::info("{$httpFailReason} — falling back to headless Chrome", ['url' => $url]);
			} elseif (str_starts_with($url, 'https://')) {
				$httpUrl = 'http://' . substr($url, 8);
				try {
					$this->ensureWithinBudget($startedAt, 'http fallback request');
					$response = $this->requestWithAuth($httpUrl, $requestOptions, $authProvided);
					$url = $httpUrl;
					$bestHtml = (string) ($response->getBody() ?? '');
					$bestText = $this->htmlToText($bestHtml);
				} catch (TransferException $e2) {
					$status2 = ($e2 instanceof RequestException) ? $e2->getResponse()?->getStatusCode() : null;
					if ($status2 === 403 || $e2 instanceof ConnectException || stripos($e2->getMessage(), 'timeout') !== false) {
						$httpFailed = true;
						$httpFailReason = $status2 === 403 ? '403 Forbidden' : 'Request timed out';
						Log::info("{$httpFailReason} on HTTP fallback — trying headless Chrome", ['url' => $url]);
					} else {
						throw $e2;
					}
				}
			} else {
				throw $e;
			}
		}

		// 2b. Use headless Chrome when HTTP failed (403/timeout), detected SPA, or thin content
		$isSpa = false;
		$needsHeadless = $httpFailed;
		if (!$needsHeadless) {
			$isSpa = $this->detectSpa($bestHtml, $bestText);
			$needsHeadless = $isSpa || strlen($bestText) < 500;
		}

		if ($needsHeadless) {
			$reason = $httpFailed ? $httpFailReason : ($isSpa ? 'SPA detected' : 'thin content');
			Log::info("HEADLESS CHROME TRIGGERED ({$reason})", ['url' => $url, 'text_length' => strlen($bestText)]);
			try {
				$this->ensureWithinBudget($startedAt, 'headless chrome fetch');
				$renderedHtml = $this->renderWithPuppeteer($url, ($httpFailed || $isSpa) ? 8000 : 2000);

				if ($renderedHtml !== null) {
					$renderedText = $this->htmlToText($renderedHtml);
					if (strlen($renderedText) > strlen($bestText)) {
						$bestHtml = $renderedHtml;
						$bestText = $renderedText;
						$httpFailed = false;
						Log::info('HEADLESS CHROME SUCCESS', ['url' => $url, 'text_length' => strlen($bestText)]);
					}
				}
			} catch (\Throwable $e) {
				Log::warning('HEADLESS CHROME FAILED', ['url' => $url, 'error' => $e->getMessage()]);
			}

			if ($httpFailed && empty($bestText)) {
				throw new \RuntimeException("Failed to scrape this URL ({$httpFailReason}). Headless Chrome also could not load the page. Please verify the URL opens in a browser.");
			}
		}

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
		$detailLinks = array_slice($detailLinks, 0, 8);
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

		$hasLoginForm = false;

		if (preg_match('/type=[\"\\\']password[\"\\\']/i', $html) && preg_match('/log in|login|sign in/i', $haystack)) {
			$hasLoginForm = true;
		}

		if (preg_match('/<form[^>]*(login|signin)[^>]*>/i', $html)) {
			$hasLoginForm = true;
		}

		if (!$hasLoginForm) {
			return false;
		}

		$bidSignals = '(bid|bids|rfp|rfq|rfi|tender|solicitation|proposal|invitation|procurement|bid opportunities|current bids|open bids|closing date|bids due)';
		if (preg_match("/{$bidSignals}/i", $haystack)) {
			return false;
		}

		return true;
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

	private function renderWithPuppeteer(string $url, int $delayMs = 3000): ?string
	{
		$script = base_path('bin/render-page.cjs');
		$nodeBin = env('NODE_BINARY', 'C:\\Program Files\\nodejs\\node.exe');

		$env = [
			'SystemRoot' => getenv('SystemRoot') ?: 'C:\\WINDOWS',
			'LOCALAPPDATA' => getenv('LOCALAPPDATA') ?: '',
			'USERPROFILE' => getenv('USERPROFILE') ?: '',
			'Path' => getenv('Path') ?: getenv('PATH') ?: '',
			'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
			'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
			'APPDATA' => getenv('APPDATA') ?: '',
			'HOME' => getenv('USERPROFILE') ?: '',
			'NODE_PATH' => base_path('node_modules'),
		];

		$process = new \Symfony\Component\Process\Process(
			[$nodeBin, $script, $url, (string) $delayMs],
			base_path(),
			$env,
			null,
			120
		);
		$process->run();

		if (!$process->isSuccessful()) {
			$error = trim($process->getErrorOutput());
			Log::warning('PUPPETEER PROCESS FAILED', ['url' => $url, 'error' => $error]);
			return null;
		}

		$html = $process->getOutput();
		if (empty(trim($html))) {
			return null;
		}

		return $html;
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

	private function detectSpa(string $html, string $text): bool
	{
		$indicators = [
			'<div id="root"',
			'<div id="app"',
			'<div id="__next"',
			'<div id="__nuxt"',
			'window.__INITIAL_STATE__',
			'window.__NEXT_DATA__',
			'ng-app=',
			'ng-version=',
			'data-reactroot',
		];
		$htmlLower = strtolower($html);
		foreach ($indicators as $indicator) {
			if (str_contains($htmlLower, strtolower($indicator))) {
				return true;
			}
		}

		$scriptCount = substr_count($htmlLower, '<script');
		$bodyContent = preg_replace('/<script[\s\S]*?<\/script>/i', '', $html);
		$bodyText = trim(strip_tags($bodyContent));
		if ($scriptCount >= 3 && strlen($bodyText) < 200) {
			return true;
		}

		return false;
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
