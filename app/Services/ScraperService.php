<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Smalot\PdfParser\Parser;

class ScraperService
{
	private Client $httpClient;
	private int $maxPdfBytes = 5242880; // 5 MB limit per PDF to avoid parser memory failures.
	private int $maxPdfParseBytes = 3145728; // Smalot can expand some PDFs heavily in memory.
	private int $pdfTimeout = 30; // Seconds per PDF download.
	private int $maxPdfDownloads = 5; // Avoid excessive PDF processing per page.
	private int $maxInteractivePages = 8; // Browser-clicked states to feed into AI.
	private int $maxInteractivePdfDownloads = 3; // PDFs discovered after UI clicks.
	private int $maxPerUrlSeconds = 180; // Hard cap per URL scrape (HTTP + Puppeteer + follow-ups).
	private int $requestTimeout = 60; // General HTTP request timeout.
	private int $maxHtmlBytes = 3145728; // Max raw HTML stored per response (3 MB).
	private int $htmlToTextMaxChars = 400000; // Cap DOM parsing to avoid multi-minute hangs on huge pages.

	// app/Services/ScraperService.php
	public function __construct()
	{
		$headers = [
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Accept-Encoding' => 'gzip, deflate',
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
				CURLOPT_ENCODING => 'gzip,deflate',
			],
		]);

		$this->maxPerUrlSeconds = max(60, (int) env('SCRAPER_MAX_PER_URL_SECONDS', 180));
		$this->maxHtmlBytes = max(524288, (int) env('SCRAPER_MAX_HTML_BYTES', 3145728));
		$this->htmlToTextMaxChars = max(100000, (int) env('SCRAPER_HTML_TO_TEXT_CAP', 400000));
	}


	public function fetch(string $url, ?string $username = null, ?string $password = null, array $options = []): array
	{
		$startedAt = microtime(true);
		$onProgress = $options['on_progress'] ?? null;
		$batchMode = !empty($options['batch']);
		$maxDetailLinks = $batchMode ? max(1, min(8, (int) env('SCRAPER_BATCH_MAX_DETAIL_PAGES', 3))) : 8;
		$maxListingPdfs = $batchMode ? max(0, min($this->maxPdfDownloads, (int) env('SCRAPER_BATCH_MAX_LISTING_PDFS', 2))) : $this->maxPdfDownloads;
		$maxDetailPdfsPerPage = $batchMode ? max(0, min(3, (int) env('SCRAPER_BATCH_MAX_DETAIL_PDFS', 1))) : PHP_INT_MAX;

		$report = function (string $message) use ($onProgress): void {
			if ($onProgress !== null) {
				try {
					($onProgress)($message);
				} catch (\Throwable) {
				}
			}
		};

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
			$shortOpts = array_merge($requestOptions, ['timeout' => 30, 'read_timeout' => 30, 'connect_timeout' => 15]);
			$response = $this->requestWithAuth($url, $shortOpts, $authProvided);
			$bestHtml = $this->limitedResponseBody($response, $startedAt);
			$bestText = $this->htmlToText($bestHtml);
		} catch (ConnectException $e) {
			Log::warning('SCRAPER CONNECT/TIMEOUT ERROR - falling back to headless Chrome', ['url' => $url, 'error' => $e->getMessage()]);
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
			$isRecoverableTransportError = $this->isRecoverableTransportError($e);

			if ($isRecoverableTransportError && str_starts_with($url, 'http://')) {
				$httpsUrl = 'https://' . substr($url, 7);
				try {
					$this->ensureWithinBudget($startedAt, 'https fallback request');
					$response = $this->requestWithAuth($httpsUrl, $requestOptions, $authProvided);
					$url = $httpsUrl;
					$finalUrl = $url;
					$bestHtml = $this->limitedResponseBody($response, $startedAt);
					$bestText = $this->htmlToText($bestHtml);
				} catch (TransferException $e2) {
					$httpFailed = true;
					$httpFailReason = $this->transportFailureReason($e2);
					Log::info("{$httpFailReason} on HTTPS fallback - trying headless Chrome", ['url' => $httpsUrl]);
				}
			} elseif ($httpStatus === 403 || $isTimeout || $isRecoverableTransportError) {
				$httpFailed = true;
				$httpFailReason = $httpStatus === 403 ? '403 Forbidden' : ($isTimeout ? 'Request timed out' : $this->transportFailureReason($e));
				Log::info("{$httpFailReason} - falling back to headless Chrome", ['url' => $url]);
			} elseif (str_starts_with($url, 'https://')) {
				$httpUrl = 'http://' . substr($url, 8);
				try {
					$this->ensureWithinBudget($startedAt, 'http fallback request');
					$response = $this->requestWithAuth($httpUrl, $requestOptions, $authProvided);
					$url = $httpUrl;
					$finalUrl = $url;
					$bestHtml = $this->limitedResponseBody($response, $startedAt);
					$bestText = $this->htmlToText($bestHtml);
				} catch (TransferException $e2) {
					$status2 = ($e2 instanceof RequestException) ? $e2->getResponse()?->getStatusCode() : null;
					if ($status2 === 403 || $e2 instanceof ConnectException || stripos($e2->getMessage(), 'timeout') !== false || $this->isRecoverableTransportError($e2)) {
						$httpFailed = true;
						$httpFailReason = $status2 === 403 ? '403 Forbidden' : $this->transportFailureReason($e2);
						Log::info("{$httpFailReason} on HTTP fallback - trying headless Chrome", ['url' => $url]);
					} else {
						throw $e2;
					}
				}
			} else {
				throw $e;
			}
		}

		// 2b. Use headless Chrome when HTTP failed, detected SPA/browser check, or thin content
		$isSpa = false;
		$isBrowserCheck = false;
		$needsHeadless = $httpFailed;
		if (!$needsHeadless) {
			$isSpa = $this->detectSpa($bestHtml, $bestText);
			$isBrowserCheck = $this->detectBrowserCheck($bestHtml, $bestText);
			$needsHeadless = $isBrowserCheck || $isSpa || strlen($bestText) < 500;
		}

		$report($needsHeadless ? 'Listing page needs browser render…' : 'Listing page loaded…');

		if ($needsHeadless) {
			$reason = $httpFailed ? $httpFailReason : ($isBrowserCheck ? 'browser check detected' : ($isSpa ? 'SPA detected' : 'thin content'));
			Log::info("HEADLESS CHROME TRIGGERED ({$reason})", ['url' => $url, 'text_length' => strlen($bestText)]);
			$report('Headless browser (Puppeteer)… this can take 1–2 minutes.');
			try {
				$this->ensureWithinBudget($startedAt, 'headless chrome fetch');
				$puppetDelay = $isBrowserCheck ? 5000 : (($httpFailed || $isSpa) ? 4000 : 1500);
				$renderedHtml = $this->renderWithPuppeteer($url, $puppetDelay, $startedAt);

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

		if (!$blocked && !$noOpenBids && !$batchMode && $this->shouldCollectInteractivePages($bestHtml, $bestText)) {
			$report('Interactive page scan (clicks/tabs)…');
			$interactivePages = $this->collectInteractivePages($url, $startedAt, $requestOptions, $authProvided);
			if (!empty($interactivePages)) {
				$bidPages = array_merge($bidPages, $interactivePages);
				Log::info('INTERACTIVE BID STATES FOUND', ['url' => $url, 'count' => count($interactivePages)]);
			}
		}

		// 3. Find PDF links (bids)
		$pdfBids = $noOpenBids ? [] : $this->findPdfLink($bestHtml, $url);

		if (!empty($pdfBids)) {
			if (count($pdfBids) > $maxListingPdfs) {
				Log::info('PDF DOWNLOADS CAPPED', ['url' => $url, 'found' => count($pdfBids), 'capped_at' => $maxListingPdfs, 'batch' => $batchMode]);
				$pdfBids = array_slice($pdfBids, 0, $maxListingPdfs);
			}

			$report('Downloading PDFs from listing (' . count($pdfBids) . ')…');

			Log::info('PDF LINKS FOUND', ['url' => $url, 'count' => count($pdfBids)]);

			$pdfTexts = [];
			foreach ($pdfBids as $idx => $bid) {
				if (!empty($bid['PDF_LINK'])) {
					$this->ensureWithinBudget($startedAt, 'pdf download loop');
					$pdfResult = $this->fetchPdf($bid['PDF_LINK'], $requestOptions, $authProvided, $startedAt);
					if (!empty($pdfResult['text'])) {
						$pdfTexts[] = $pdfResult['text'];
					} else {
						$pdfBids[$idx]['SKIP_PDF_PARSE'] = true;
						$pdfBids[$idx]['PDF_ERROR'] = $pdfResult['error'] ?? 'PDF text could not be extracted safely.';
					}
				}
			}
			$pdfText = implode("\n\n----- NEXT DOCUMENT -----\n\n", $pdfTexts);
		} else {
			Log::info('NO PDF LINK FOUND', ['url' => $url]);
		}

		// 3b. Follow clickable bid titles (detail pages) to pull richer data and PDFs
		$detailLinks = $noOpenBids ? [] : $this->findBidDetailLinks($bestHtml, $url);
		$detailLinks = array_slice($detailLinks, 0, $maxDetailLinks);
		$detailTotal = count($detailLinks);
		$detailIdx = 0;
		foreach ($detailLinks as $detail) {
			$detailIdx++;
			try {
				$report("Detail page {$detailIdx}/{$detailTotal}…");
				$pageHtml = '';
				$pageText = '';
				$pagePdfText = '';
				$pagePdfLinks = [];

				$this->ensureWithinBudget($startedAt, 'detail page request');
				$response = $this->requestWithAuth($detail['URL'], $requestOptions, $authProvided);
				$pageHtml = $this->limitedResponseBody($response, $startedAt);
				$pageText = $this->htmlToText($pageHtml);
				$this->guardLoginRequirement($pageHtml, $pageText, $authProvided);
				$pagePdfLinks = $this->findPdfLink($pageHtml, $detail['URL']);
				if ($maxDetailPdfsPerPage >= 0 && count($pagePdfLinks) > $maxDetailPdfsPerPage) {
					$pagePdfLinks = array_slice($pagePdfLinks, 0, $maxDetailPdfsPerPage);
				}

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

	private function isRecoverableTransportError(\Throwable $e): bool
	{
		$message = strtolower($e->getMessage());

		$signals = [
			'curl error 28',
			'curl error 52',
			'curl error 56',
			'curl error 61',
			'recv failure',
			'connection was reset',
			'connection reset',
			'empty reply from server',
			'unrecognized content encoding',
			'timed out',
			'timeout',
		];

		foreach ($signals as $signal) {
			if (str_contains($message, $signal)) {
				return true;
			}
		}

		return false;
	}

	private function transportFailureReason(\Throwable $e): string
	{
		$message = strtolower($e->getMessage());

		if (str_contains($message, 'unrecognized content encoding')) {
			return 'Unsupported response encoding';
		}
		if (str_contains($message, 'curl error 56') || str_contains($message, 'connection reset') || str_contains($message, 'recv failure')) {
			return 'Connection was reset';
		}
		if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
			return 'Request timed out';
		}
		if (str_contains($message, 'empty reply') || str_contains($message, 'curl error 52')) {
			return 'Empty reply from server';
		}

		return 'Transport error';
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
				$linkText = $this->normalizeWhitespace($node->textContent ?? '');
				$parentText = $this->nearbyText($node);
				$context = trim($linkText . ' ' . $parentText . ' ' . $attr . ' ' . $pdfUrl);
				if (!$this->looksLikeBidDocument($context, $baseUrl)) {
					continue;
				}

				$bids[] = [
					'TITLE' => strlen($linkText) > 4 ? $linkText : $this->extractTitleFromUrl($pdfUrl),
					'PDF_LINK' => $pdfUrl,
				];
			}
		}

		// fallback: detect raw .pdf URLs in text
		if (empty($bids) && preg_match_all('/https?:\/\/[^\\s"\']+\.pdf/i', $html, $matches)) {
			foreach ($matches[0] as $pdfUrl) {
				if (!$this->looksLikeBidDocument($pdfUrl, $baseUrl)) {
					continue;
				}

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

	private function normalizeWhitespace(string $text): string
	{
		return trim(preg_replace('/\s+/', ' ', $text) ?? '');
	}

	private function nearbyText(\DOMNode $node): string
	{
		$text = '';
		$current = $node->parentNode;
		for ($i = 0; $i < 3 && $current; $i++, $current = $current->parentNode) {
			$text = $this->normalizeWhitespace($current->textContent ?? '');
			if (strlen($text) > 20) {
				break;
			}
		}

		return mb_substr($text, 0, 500);
	}

	private function looksLikeBidDocument(string $context, string $baseUrl): bool
	{
		$haystack = strtolower($context . ' ' . $baseUrl);

		$negativeSignals = [
			'accessibility',
			'americans with disabilities',
			'annual report',
			'ap contacts',
			'ap-contact',
			'background check',
			'brochure',
			'code report',
			'contact guide',
			'ethics',
			'manual',
			'mission',
			'policy',
			'procedure',
			'procurement code',
			'responsible contractor policy',
			'social media',
			'standard terms',
			'suspended',
			'debarred',
			'terms and conditions',
			'vendor guide',
			'vision',
			'w-9',
			'w9',
		];

		foreach ($negativeSignals as $signal) {
			if (str_contains($haystack, $signal)) {
				return false;
			}
		}

		$positiveSignals = [
			'addendum',
			'amendment',
			'bid',
			'bids',
			'formalbid',
			'formal bid',
			'ifb',
			'invitation',
			'itb',
			'proposal',
			'quote',
			'request for',
			'rfi',
			'rfp',
			'rfq',
			'solicitation',
			'specification',
			'tender',
		];

		foreach ($positiveSignals as $signal) {
			if (str_contains($haystack, $signal)) {
				return true;
			}
		}

		return (bool) preg_match('/\b[a-z]{1,6}\d{2,}[-_a-z0-9]*\b/i', $context);
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

	private function shouldCollectInteractivePages(string $html, string $text): bool
	{
		$enabled = strtolower(trim((string) env('SCRAPER_INTERACTIONS', 'true')));
		if (in_array($enabled, ['0', 'false', 'no', 'off'], true)) {
			return false;
		}

		if ($html === '' || $this->detectBrowserCheck($html, $text)) {
			return false;
		}

		$hasControls = preg_match('/<(button|select|summary)\b|role=["\'](?:button|tab|combobox)|aria-expanded=|data-toggle=|accordion|dropdown|tab/i', $html);
		if (!$hasControls) {
			return false;
		}

		$haystack = strtolower($text . ' ' . strip_tags(mb_substr($html, 0, 80000)));
		return (bool) preg_match('/bid|bids|rfp|rfq|rfi|ifb|itb|solicitation|opportunit|procurement|proposal|quote|tender|addendum|attachment|document|due date|closing date/', $haystack);
	}

	private function collectInteractivePages(string $url, float $startedAt, array $requestOptions, bool $authProvided): array
	{
		try {
			$this->ensureWithinBudget($startedAt, 'interactive browser scan');

			$script = base_path('bin/collect-interactions.cjs');
			if (!file_exists($script)) {
				return [];
			}

			$nodeBin = trim((string) env('NODE_BINARY', ''));
			if ($nodeBin === '' || ((str_contains($nodeBin, '\\') || str_contains($nodeBin, '/')) && !file_exists($nodeBin))) {
				$nodeBin = $this->findNodeBinary() ?? 'node';
			}

			$elapsed = microtime(true) - $startedAt;
			$subTimeout = (int) max(25, min(90, floor($this->maxPerUrlSeconds - $elapsed - 5)));

			$process = new \Symfony\Component\Process\Process(
				[$nodeBin, $script, $url, '2500', (string) $this->maxInteractivePages],
				base_path(),
				$this->nodeProcessEnv($nodeBin),
				null,
				$subTimeout
			);
			$process->run();

			if (!$process->isSuccessful()) {
				Log::warning('INTERACTIVE SCAN FAILED', ['url' => $url, 'error' => trim($process->getErrorOutput())]);
				return [];
			}

			$data = json_decode($process->getOutput(), true);
			if (!is_array($data) || empty($data['pages']) || !is_array($data['pages'])) {
				return [];
			}

			$pages = [];
			$seen = [];
			$pdfDownloads = 0;

			foreach ($data['pages'] as $page) {
				$html = (string) ($page['html'] ?? '');
				if (strlen($html) > $this->maxHtmlBytes) {
					$html = substr($html, 0, $this->maxHtmlBytes);
				}
				$text = $this->htmlToText($html);
				if ($text === '') {
					$text = trim((string) ($page['text'] ?? ''));
				}
				if (strlen($text) < 80 || $this->detectBlockedPage($html, $text)) {
					continue;
				}

				$fingerprint = md5(mb_substr($text, 0, 20000));
				if (isset($seen[$fingerprint])) {
					continue;
				}
				$seen[$fingerprint] = true;

				$pageUrl = filter_var($page['url'] ?? '', FILTER_VALIDATE_URL) ? (string) $page['url'] : $url;
				$pdfLinks = $this->findPdfLink($html, $pageUrl);
				$pagePdfText = '';
				$pdfTexts = [];

				foreach ($pdfLinks as $idx => $link) {
					if ($pdfDownloads >= $this->maxInteractivePdfDownloads) {
						break;
					}
					if (empty($link['PDF_LINK'])) {
						continue;
					}

					$this->ensureWithinBudget($startedAt, 'interactive pdf loop');
					$pdfResult = $this->fetchPdf($link['PDF_LINK'], $requestOptions, $authProvided, $startedAt);
					$pdfDownloads++;
					if (!empty($pdfResult['text'])) {
						$pdfTexts[] = $pdfResult['text'];
					} else {
						$pdfLinks[$idx]['SKIP_PDF_PARSE'] = true;
						$pdfLinks[$idx]['PDF_ERROR'] = $pdfResult['error'] ?? 'PDF text could not be extracted safely.';
					}
				}

				$pagePdfText = implode("\n\n----- NEXT DOCUMENT -----\n\n", $pdfTexts);
				$label = trim((string) ($page['label'] ?? 'Interactive page state'));

				$pages[] = [
					'title' => $label !== '' ? $label : 'Interactive page state',
					'url' => $pageUrl,
					'html' => $html,
					'text' => $text,
					'pdf_links' => $pdfLinks,
					'pdf_text' => $pagePdfText,
					'source' => 'browser_interaction',
					'interaction_type' => $page['interaction_type'] ?? '',
				];

				if (count($pages) >= $this->maxInteractivePages) {
					break;
				}
			}

			return $pages;
		} catch (\Throwable $e) {
			Log::warning('INTERACTIVE SCAN ERROR', ['url' => $url, 'error' => $e->getMessage()]);
			return [];
		}
	}

	private function fetchPdf(string $url, array $requestOptions, bool $authProvided, float $startedAt): array
	{
		$tempFile = null;
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
			if (!empty($lenHeader) && (int) $lenHeader > $this->maxPdfParseBytes) {
				throw new \RuntimeException("PDF is larger than the safe parser limit (" . $this->formatBytes($this->maxPdfParseBytes) . ").");
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
				if ($downloaded > $this->maxPdfParseBytes) {
					throw new \RuntimeException("PDF exceeds safe parser limit (" . $this->formatBytes($this->maxPdfParseBytes) . ").");
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
			$tempFile = null;

			return [
				'final_url' => $url,
				'text' => $text,
				'is_pdf' => true,
			];
		} catch (\Throwable $e) {
			if (is_resource($tempFile)) {
				fclose($tempFile);
			}
			Log::error('PDF FETCH ERROR', ['url' => $url, 'error' => $e->getMessage()]);
			return [
				'final_url' => $url,
				'text' => '',
				'is_pdf' => true,
				'error' => $e->getMessage(),
			];
		}
	}

	private function limitedResponseBody(ResponseInterface $response, float $startedAt): string
	{
		$stream = $response->getBody();
		$buf = '';
		while (!$stream->eof()) {
			$this->ensureWithinBudget($startedAt, 'downloading HTML');
			$chunk = $stream->read(65536);
			if ($chunk === '') {
				break;
			}
			$buf .= $chunk;
			if (strlen($buf) >= $this->maxHtmlBytes) {
				Log::warning('SCRAPER HTML TRUNCATED', ['max_bytes' => $this->maxHtmlBytes]);
				break;
			}
		}

		return $buf;
	}

	private function renderWithPuppeteer(string $url, int $delayMs, float $startedAt): ?string
	{
		$this->ensureWithinBudget($startedAt, 'before headless chrome');
		$elapsed = microtime(true) - $startedAt;
		$remaining = $this->maxPerUrlSeconds - $elapsed - 3;
		if ($remaining < 12) {
			Log::warning('PUPPETEER SKIPPED (no time left in budget)', ['url' => $url, 'remaining_sec' => $remaining]);
			return null;
		}
		$processTimeout = (int) max(20, min(85, $remaining));
		$delayMs = min(max(0, $delayMs), 6000);

		$script = base_path('bin/render-page.cjs');
		$nodeBin = trim((string) env('NODE_BINARY', ''));
		if ($nodeBin === '' || ((str_contains($nodeBin, '\\') || str_contains($nodeBin, '/')) && !file_exists($nodeBin))) {
			$nodeBin = $this->findNodeBinary() ?? 'node';
		}

		$process = new \Symfony\Component\Process\Process(
			[$nodeBin, $script, $url, (string) $delayMs, (string) min(45000, $processTimeout * 1000)],
			base_path(),
			$this->nodeProcessEnv($nodeBin),
			null,
			$processTimeout
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

		return strlen($html) > $this->maxHtmlBytes ? substr($html, 0, $this->maxHtmlBytes) : $html;
	}

	private function findNodeBinary(): ?string
	{
		$binaryName = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'node.exe' : 'node';
		$candidates = [
			'C:\\nvm4w\\nodejs\\node.exe',
			'C:\\Program Files\\nodejs\\node.exe',
			'C:\\Program Files (x86)\\nodejs\\node.exe',
		];

		foreach ($candidates as $candidate) {
			if (file_exists($candidate)) {
				return $candidate;
			}
		}

		$path = getenv('Path') ?: getenv('PATH') ?: '';
		foreach (explode(PATH_SEPARATOR, $path) as $dir) {
			$dir = trim($dir);
			if ($dir === '') {
				continue;
			}

			$candidate = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $binaryName;
			if (file_exists($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	private function nodeProcessEnv(string $nodeBin): array
	{
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

		if ((str_contains($nodeBin, '\\') || str_contains($nodeBin, '/')) && file_exists($nodeBin)) {
			$env['Path'] = dirname($nodeBin) . PATH_SEPARATOR . $env['Path'];
		}

		$cookieHeader = trim((string) env('SCRAPER_COOKIE', ''));
		if ($cookieHeader !== '') {
			$env['SCRAPER_COOKIE'] = $cookieHeader;
		}

		return $env;
	}

	private function htmlToText(string $html): string
	{
		if (strlen($html) > $this->htmlToTextMaxChars) {
			$html = substr($html, 0, $this->htmlToTextMaxChars);
		}

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
		if ($this->detectBrowserCheck($html, $text)) {
			return 'browser check/captcha';
		}

		$haystack = strtolower($text . ' ' . strip_tags($html));
		$signals = [
			'blocked country',
			'sorry, you have been blocked',
			'unable to access',
			'cloudflare',
			'geolocation setting',
			'connection was denied because this country',
			'watchguard',
			'wrong captcha answer',
			'recaptcha',
		];

		foreach ($signals as $signal) {
			if (str_contains($haystack, $signal)) {
				return $signal;
			}
		}

		return null;
	}

	private function detectBrowserCheck(string $html, string $text): bool
	{
		$haystack = strtolower($text . ' ' . strip_tags($html));

		$signals = [
			'please wait while we are checking your browser',
			'browser check',
			'checking your browser',
			'dom is busy',
		];

		$matched = 0;
		foreach ($signals as $signal) {
			if (str_contains($haystack, $signal)) {
				$matched++;
			}
		}

		return $matched >= 2;
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
