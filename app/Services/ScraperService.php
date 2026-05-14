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
	private int $maxPerUrlSeconds = 180; // Hard cap per URL scrape (HTTP + Puppeteer + follow-ups); see SCRAPER_MAX_PER_URL_SECONDS.
	/** @var int|null Raised for one fetch() on heavy JS portals (SCRAPER_HEAVY_PORTAL_MAX_URL_SECONDS). */
	private ?int $fetchBudgetSeconds = null;
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
		$this->pdfTimeout = max(15, min(180, (int) env('SCRAPER_PDF_TIMEOUT', 60)));
	}


	public function fetch(string $url, ?string $username = null, ?string $password = null, array $options = []): array
	{
		$startedAt = microtime(true);
		$onProgress = $options['on_progress'] ?? null;
		$batchMode = !empty($options['batch']);
		$maxDetailLinks = $batchMode ? max(0, min(8, (int) env('SCRAPER_BATCH_MAX_DETAIL_PAGES', 3))) : 8;
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

		$this->fetchBudgetSeconds = null;
		if ($this->isHeavyJsProcurementPortal($url)) {
			$heavyBudget = max(60, (int) env('SCRAPER_HEAVY_PORTAL_MAX_URL_SECONDS', 540));
			$this->fetchBudgetSeconds = max($this->maxPerUrlSeconds, $heavyBudget);
		}

		try {
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
				if ($this->isHeavyJsProcurementPortal($url)) {
					$puppetDelay = max($puppetDelay, (int) env('SCRAPER_HEAVY_PORTAL_SETTLE_MS', 22000));
				}
				$renderedHtml = $this->renderWithPuppeteer($url, $puppetDelay, $startedAt, false);

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

		$bestText = $this->prependComplianceNewsPrimaryDescription($finalUrl, $bestText);

		if (!$blocked && !$noOpenBids && (!$batchMode || $this->isHeavyJsProcurementPortal($url)) && $this->shouldCollectInteractivePages($bestHtml, $bestText)) {
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
			$pdfTotal = count($pdfBids);
			foreach ($pdfBids as $idx => $bid) {
				if (!empty($bid['PDF_LINK'])) {
					$this->ensureWithinBudget($startedAt, 'pdf download loop');
					$report('Listing PDF ' . ((int) $idx + 1) . '/' . $pdfTotal . '…');
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
		$detailLinks = ($noOpenBids || $maxDetailLinks === 0) ? [] : $this->findBidDetailLinks($bestHtml, $url);
		$detailLinks = array_slice($detailLinks, 0, $maxDetailLinks);
		$detailTotal = count($detailLinks);
		$detailIdx = 0;
		foreach ($detailLinks as $detail) {
			$detailIdx++;
			try {
				$headlessDetail = $this->detailUrlRequiresHeadlessFetch($detail['URL']);
				$report($headlessDetail ? "Detail page {$detailIdx}/{$detailTotal} (browser)…" : "Detail page {$detailIdx}/{$detailTotal}…");
				$pageHtml = '';
				$pageText = '';
				$pagePdfText = '';
				$pagePdfLinks = [];

				$this->ensureWithinBudget($startedAt, 'detail page request');
				if ($headlessDetail) {
					$detailDelay = max(2000, min(12000, (int) env('SCRAPER_DETAIL_PUPPETEER_DELAY_MS', 6000)));
					$pageHtml = $this->renderWithPuppeteer($detail['URL'], $detailDelay, $startedAt, true);
					if ($pageHtml === null || trim($pageHtml) === '') {
						throw new \RuntimeException('Headless detail fetch returned no HTML.');
					}
				} else {
					$response = $this->requestWithAuth($detail['URL'], $requestOptions, $authProvided);
					$pageHtml = $this->limitedResponseBody($response, $startedAt);
				}
				$pageText = $this->htmlToText($pageHtml);
				$pageText = $this->prependComplianceNewsPrimaryDescription($detail['URL'], $pageText);
				$this->guardLoginRequirement($pageHtml, $pageText, $authProvided);
				$pagePdfLinks = $this->findPdfLink($pageHtml, $detail['URL']);
				if ($maxDetailPdfsPerPage >= 0 && count($pagePdfLinks) > $maxDetailPdfsPerPage) {
					$pagePdfLinks = array_slice($pagePdfLinks, 0, $maxDetailPdfsPerPage);
				}

				$pdfTexts = [];
				foreach ($pagePdfLinks as $i => $link) {
					if (!empty($link['PDF_LINK'])) {
						$this->ensureWithinBudget($startedAt, 'detail pdf loop');
						$report('Detail PDF ' . ((int) $i + 1) . '/' . count($pagePdfLinks) . '…');
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
		} finally {
			$this->fetchBudgetSeconds = null;
		}
	}

	/**
	 * Hosts known to need long headless settle / higher process budget (React/Angular portals).
	 * Extend via SCRAPER_HEAVY_PORTAL_HOST_SUFFIXES=comma,separated
	 */
	private function isHeavyJsProcurementPortal(string $url): bool
	{
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));
		if ($host === '') {
			return false;
		}

		$suffixes = ['bonfirehub.com'];
		foreach (array_filter(array_map('trim', explode(',', (string) env('SCRAPER_HEAVY_PORTAL_HOST_SUFFIXES', '')))) as $s) {
			if ($s !== '') {
				$suffixes[] = strtolower($s);
			}
		}

		foreach (array_unique($suffixes) as $s) {
			if ($s === '') {
				continue;
			}
			if ($host === $s || str_ends_with($host, '.' . $s)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Some portals (e.g. Bonfire behind Cloudflare) return "Just a moment…" to Guzzle but render in real Chrome.
	 */
	private function detailUrlRequiresHeadlessFetch(string $detailUrl): bool
	{
		if (!filter_var(env('SCRAPER_DETAIL_HEADLESS_FETCH', true), FILTER_VALIDATE_BOOLEAN)) {
			return false;
		}

		$host = strtolower((string) parse_url($detailUrl, PHP_URL_HOST));
		if ($host === '') {
			return false;
		}

		$suffixes = ['bonfirehub.com'];
		foreach (array_filter(array_map('trim', explode(',', (string) env('SCRAPER_DETAIL_HEADLESS_HOST_SUFFIXES', '')))) as $s) {
			if ($s !== '') {
				$suffixes[] = strtolower($s);
			}
		}

		foreach (array_unique($suffixes) as $s) {
			if ($s === '') {
				continue;
			}
			if ($host === $s || str_ends_with($host, '.' . $s)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compliance News trade-journal detail pages embed the real scope under "PROJECT DETAILS" / "PROJECT DESCRIPTION".
	 * Prepend that block so the model uses the narrative for DESCRIPTION instead of title/status/state metadata only.
	 */
	private function prependComplianceNewsPrimaryDescription(string $pageUrl, string $plainText): string
	{
		$hint = $this->extractComplianceNewsPrimaryDescription($pageUrl, $plainText);
		if ($hint === null || $hint === '') {
			return $plainText;
		}

		return "--- PRIMARY PROJECT DESCRIPTION (use the full text below for DESCRIPTION; do not replace with a short metadata summary) ---\n\n"
			. $hint
			. "\n\n--- PAGE TEXT ---\n\n"
			. $plainText;
	}

	/**
	 * @return non-empty-string|null
	 */
	private function extractComplianceNewsPrimaryDescription(string $pageUrl, string $plainText): ?string
	{
		$host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
		if ($host === '' || !str_contains($host, 'compliancenews.com')) {
			return null;
		}

		$t = preg_replace("/\r\n?/", "\n", $plainText);
		if (!is_string($t) || $t === '') {
			return null;
		}

		$stop = '(?=\n\s*PDF\s+ATTACHMENTS\b|\nBack\s+To\s+Trade\s+Journal\b|\z)';
		$patterns = [
			'/PROJECT\s+DETAILS\s*:?\s*\n?([\s\S]+?)' . $stop . '/iu',
			'/PROJECT\s+DESCRIPTION\s*:?\s*\n?([\s\S]+?)' . $stop . '/iu',
		];

		foreach ($patterns as $re) {
			if (preg_match($re, $t, $m)) {
				$block = trim($m[1]);
				if (mb_strlen($block) >= 120) {
					$block = preg_replace("/\n{3,}/", "\n\n", $block);

					return mb_substr($block, 0, 120000);
				}
			}
		}

		return null;
	}

	/**
	 * OpenGov "portal" URLs with department filters return Cloudflare interstitials to server-side clients (403 + "Just a moment…"), not bid detail pages.
	 */
	private function isOpenGovPortalListingUrl(string $url): bool
	{
		if (filter_var(env('SCRAPER_ALLOW_OPENGOV_PORTAL_DETAIL_LINKS', false), FILTER_VALIDATE_BOOLEAN)) {
			return false;
		}

		$parts = parse_url($url);
		if (empty($parts['host'])) {
			return false;
		}

		$host = strtolower($parts['host']);
		if ($host !== 'procurement.opengov.com' && !str_ends_with($host, '.procurement.opengov.com')) {
			return false;
		}

		$path = strtolower($parts['path'] ?? '');
		$query = $parts['query'] ?? '';

		return str_contains($path, '/portal/')
			&& (str_contains($query, 'departmentId=') || str_contains($query, 'departmentid='));
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
		$cap = $this->fetchBudgetSeconds ?? $this->maxPerUrlSeconds;
		if ($elapsed > $cap) {
			throw new \RuntimeException("Scrape timed out while processing {$context}. Please retry later or reduce the amount of content to download.");
		}
	}

	private function logPuppeteerDiagnostics(string $url, string $stderr): void
	{
		if ($stderr === '') {
			return;
		}
		if (str_contains($stderr, 'Could not find Chrome') || str_contains($stderr, 'chrome-headless-shell')) {
			Log::warning('PUPPETEER BROWSER BINARIES MISSING', [
				'url' => $url,
				'hint' => 'As php-fpm user: bash bin/install-puppeteer-browsers.sh (Chrome + chrome-headless-shell into storage/app/puppeteer-cache). SCRAPER_CHROME_HEADLESS=shell requires chrome-headless-shell; omit shell if you only installed full Chrome.',
			]);
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
				if ($this->hrefLooksLikeClientTemplate($attr) || $this->hrefLooksLikeClientTemplate($pdfUrl)) {
					continue;
				}
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
			'protest protocol',
			'pc_protest',
			'spirit airlines shutdown',
			'shutdown flymco',
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
			if ($this->isNonHttpDocumentLink($href)) {
				continue;
			}

			$resolved = $this->resolveUrl($href, $baseUrl);
			if ($this->isNonHttpDocumentLink($resolved)) {
				continue;
			}
			if ($this->hrefLooksLikeClientTemplate($href) || $this->hrefLooksLikeClientTemplate($resolved)) {
				continue;
			}
			if ($this->isOpenGovPortalListingUrl($resolved)) {
				continue;
			}
			if ($this->urlsSameDocumentIgnoringFragment($resolved, $baseUrl)) {
				continue;
			}
			if ($this->isBonfireHubHost($resolved) && !$this->isBonfireOpportunityDetailUrl($resolved)) {
				continue;
			}

			$lower = strtolower($text . ' ' . $resolved);
			if (!preg_match('/bid|rfp|rfq|tender|solicitation|proposal|opportunit/i', $lower)) {
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

	/** Broward-style Bonfire hubs use /opportunities/{id} for bid detail; /portal tabs and #hash nav are not separate documents. */
	private function isBonfireHubHost(string $url): bool
	{
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		return $host !== '' && ($host === 'bonfirehub.com' || str_ends_with($host, '.bonfirehub.com'));
	}

	private function isBonfireOpportunityDetailUrl(string $url): bool
	{
		$path = parse_url($url, PHP_URL_PATH);

		return is_string($path) && $path !== '' && (bool) preg_match('#^/opportunities/\d+#i', $path);
	}

	/** Compare scheme, host, port, path, query; ignore #fragment (in-page / tab links). */
	private function urlsSameDocumentIgnoringFragment(string $a, string $b): bool
	{
		$pa = parse_url($a);
		$pb = parse_url($b);
		if (($pa['scheme'] ?? '') === '' || ($pb['scheme'] ?? '') === '') {
			return false;
		}
		if (strtolower($pa['scheme']) !== strtolower($pb['scheme'])) {
			return false;
		}
		if (strtolower($pa['host'] ?? '') !== strtolower($pb['host'] ?? '')) {
			return false;
		}
		$portA = $pa['port'] ?? null;
		$portB = $pb['port'] ?? null;
		if (($portA ?? $this->defaultPortForUrlScheme($pa['scheme'])) !== ($portB ?? $this->defaultPortForUrlScheme($pb['scheme']))) {
			return false;
		}
		$pathA = $this->normalizeUrlPathForCompare($pa['path'] ?? '');
		$pathB = $this->normalizeUrlPathForCompare($pb['path'] ?? '');
		if ($pathA !== $pathB) {
			return false;
		}

		return ($pa['query'] ?? '') === ($pb['query'] ?? '');
	}

	private function defaultPortForUrlScheme(string $scheme): ?int
	{
		return match (strtolower($scheme)) {
			'http' => 80,
			'https' => 443,
			default => null,
		};
	}

	private function normalizeUrlPathForCompare(string $path): string
	{
		if ($path === '') {
			return '/';
		}

		return rtrim($path, '/') ?: '/';
	}

	/**
	 * Skip SPA hrefs that still contain server-side or client template tokens (e.g. Underscore &lt;%- auction.ProjectID %&gt;).
	 * Following these produces invalid URLs and often Cloudflare 403.
	 */
	private function hrefLooksLikeClientTemplate(string $href): bool
	{
		if ($href === '') {
			return false;
		}

		foreach ([$href, rawurldecode($href), urldecode($href)] as $chunk) {
			if (str_contains($chunk, '<%') || str_contains($chunk, '%>')) {
				return true;
			}
			if (preg_match('/\{\{\s*[^}]+\s*\}\}/', $chunk)) {
				return true;
			}
			if (str_contains($chunk, '${')) {
				return true;
			}
		}

		return false;
	}

	/** mailto:/tel:/sms: are not HTTP documents; do not enqueue for GET. */
	private function isNonHttpDocumentLink(string $href): bool
	{
		return $href !== '' && (bool) preg_match('/^\s*(mailto|tel|sms|fax|data|blob):/i', $href);
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
			$heavyInteractiveCap = max(60, (int) env('SCRAPER_HEAVY_PORTAL_INTERACTIVE_MAX_SEC', 180));
			$interactiveCap = $this->isHeavyJsProcurementPortal($url) ? $heavyInteractiveCap : 90;
			$budgetCap = $this->fetchBudgetSeconds ?? $this->maxPerUrlSeconds;
			$subTimeout = (int) max(25, min($interactiveCap, floor($budgetCap - $elapsed - 5)));

			$process = new \Symfony\Component\Process\Process(
				[$nodeBin, $script, $url, '2500', (string) $this->maxInteractivePages],
				base_path(),
				$this->nodeProcessEnv($nodeBin),
				null,
				$subTimeout
			);
			$process->run();

			if (!$process->isSuccessful()) {
				$err = trim($process->getErrorOutput());
				Log::warning('INTERACTIVE SCAN FAILED', ['url' => $url, 'error' => $err]);
				if (str_contains($err, 'libxkbcommon') || str_contains($err, 'error while loading shared libraries') || str_contains($err, 'Code: 127')) {
					Log::warning('PUPPETEER MISSING OS LIBS', ['url' => $url, 'hint' => 'On Amazon Linux EC2 run: bash bin/install-puppeteer-chrome-deps-amzn.sh (then restart php-fpm).']);
				}
				if (str_contains($err, 'crashpad') || str_contains($err, 'Browser process')) {
					Log::warning('PUPPETEER CRASHPAD', ['url' => $url, 'hint' => 'Try SCRAPER_CHROME_SINGLE_PROCESS=true and/or SCRAPER_CHROME_USER_DATA_DIR=/tmp/chrome-scraper (writable by php-fpm user) in .env.']);
				}
				if (str_contains($err, 'Target closed') || str_contains($err, 'Protocol error')) {
					Log::warning('PUPPETEER PROTOCOL', ['url' => $url, 'hint' => 'Try leaving SCRAPER_CHROME_PIPE unset; SCRAPER_CHROME_HEADLESS=shell after bin/install-puppeteer-browsers.sh; or PUPPETEER_EXECUTABLE_PATH to system Chromium.']);
				}
				$this->logPuppeteerDiagnostics($url, $err);
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
				$text = $this->prependComplianceNewsPrimaryDescription($pageUrl, $text);
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
			// Buffered download: some CDNs reset chunked streams; avoid gzip on binary.
			$options = array_merge($requestOptions, [
				'timeout' => $this->pdfTimeout,
				'read_timeout' => $this->pdfTimeout,
				'connect_timeout' => 20,
				'decode_content' => false,
				'headers' => [
					'Accept' => 'application/pdf,*/*;q=0.9',
				],
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

			$this->ensureWithinBudget($startedAt, 'pdf body read');
			$raw = $response->getBody()->getContents();
			if (strlen($raw) > $this->maxPdfBytes) {
				throw new \RuntimeException("PDF exceeds size limit (" . $this->formatBytes($this->maxPdfBytes) . ").");
			}
			if (strlen($raw) > $this->maxPdfParseBytes) {
				throw new \RuntimeException("PDF exceeds safe parser limit (" . $this->formatBytes($this->maxPdfParseBytes) . ").");
			}

			$tempFile = tmpfile();
			$meta = stream_get_meta_data($tempFile);
			fwrite($tempFile, $raw);
			rewind($tempFile);
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
			$msg = $e->getMessage();
			$hint = '';
			if (stripos($msg, 'stream') !== false || stripos($msg, 'timeout') !== false) {
				$hint = ' (try SCRAPER_PDF_TIMEOUT in .env; CDN may throttle server IPs)';
			}
			Log::warning('PDF FETCH ERROR', ['url' => $url, 'error' => $msg, 'hint' => $hint]);
			return [
				'final_url' => $url,
				'text' => '',
				'is_pdf' => true,
				'error' => $msg . $hint,
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

	private function renderWithPuppeteer(string $url, int $delayMs, float $startedAt, bool $detailPagePass = false): ?string
	{
		$this->ensureWithinBudget($startedAt, 'before headless chrome');
		$elapsed = microtime(true) - $startedAt;
		$budgetCap = $this->fetchBudgetSeconds ?? $this->maxPerUrlSeconds;
		$remaining = $budgetCap - $elapsed - 3;
		if ($remaining < 12) {
			Log::warning('PUPPETEER SKIPPED (no time left in budget)', ['url' => $url, 'remaining_sec' => $remaining]);
			return null;
		}
		$heavy = $this->isHeavyJsProcurementPortal($url);
		if ($detailPagePass) {
			$processTimeoutSec = (int) max(35, min(150, $remaining));
		} else {
			$heavyPuppetCap = max(90, (int) env('SCRAPER_HEAVY_PORTAL_PUPPETEER_MAX_SEC', 240));
			$processTimeoutSec = $heavy
				? min($heavyPuppetCap, (int) max(25, $remaining))
				: (int) max(20, min(85, $remaining));
		}
		$maxDelayCap = $heavy ? 30000 : 6000;
		$delayMs = min(max(0, $delayMs), $maxDelayCap);

		$script = base_path('bin/render-page.cjs');
		$nodeBin = trim((string) env('NODE_BINARY', ''));
		if ($nodeBin === '' || ((str_contains($nodeBin, '\\') || str_contains($nodeBin, '/')) && !file_exists($nodeBin))) {
			$nodeBin = $this->findNodeBinary() ?? 'node';
		}

		$defaultNavMs = ($detailPagePass && !$heavy) ? 45000 : ($heavy ? 90000 : 45000);
		$navTimeoutMs = min($defaultNavMs, $processTimeoutSec * 1000);

		$process = new \Symfony\Component\Process\Process(
			[$nodeBin, $script, $url, (string) $delayMs, (string) $navTimeoutMs],
			base_path(),
			$this->nodeProcessEnv($nodeBin),
			null,
			$processTimeoutSec
		);
		$process->run();

		if (!$process->isSuccessful()) {
			$error = trim($process->getErrorOutput());
			Log::warning('PUPPETEER PROCESS FAILED', ['url' => $url, 'error' => $error]);
			if (str_contains($error, 'libxkbcommon') || str_contains($error, 'error while loading shared libraries') || str_contains($error, 'Code: 127')) {
				Log::warning('PUPPETEER MISSING OS LIBS', ['url' => $url, 'hint' => 'On Amazon Linux EC2 run: bash bin/install-puppeteer-chrome-deps-amzn.sh (then restart php-fpm).']);
			}
			if (str_contains($error, 'crashpad') || str_contains($error, 'Browser process')) {
				Log::warning('PUPPETEER CRASHPAD', ['url' => $url, 'hint' => 'Try SCRAPER_CHROME_SINGLE_PROCESS=true and/or SCRAPER_CHROME_USER_DATA_DIR=/tmp/chrome-scraper in .env.']);
			}
			if (str_contains($error, 'Target closed') || str_contains($error, 'Protocol error')) {
				Log::warning('PUPPETEER PROTOCOL', ['url' => $url, 'hint' => 'Leave SCRAPER_CHROME_PIPE unset; use SCRAPER_CHROME_HEADLESS=shell only after install-puppeteer-browsers.sh; or set PUPPETEER_EXECUTABLE_PATH.']);
			}
			$this->logPuppeteerDiagnostics($url, $error);
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
		$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
		$home = $isWindows
			? (getenv('USERPROFILE') ?: getenv('HOME') ?: '')
			: (getenv('HOME') ?: '');

		$env = [
			'SystemRoot' => getenv('SystemRoot') ?: 'C:\\WINDOWS',
			'LOCALAPPDATA' => getenv('LOCALAPPDATA') ?: '',
			'USERPROFILE' => getenv('USERPROFILE') ?: '',
			'Path' => getenv('Path') ?: getenv('PATH') ?: '',
			'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
			'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
			'APPDATA' => getenv('APPDATA') ?: '',
			'HOME' => $home,
			'NODE_PATH' => base_path('node_modules'),
		];

		if ((str_contains($nodeBin, '\\') || str_contains($nodeBin, '/')) && file_exists($nodeBin)) {
			$env['Path'] = dirname($nodeBin) . PATH_SEPARATOR . $env['Path'];
		}

		$puppeteerCache = trim((string) env('PUPPETEER_CACHE_DIR', ''));
		if ($puppeteerCache === '' && !$isWindows) {
			$puppeteerCache = storage_path('app/puppeteer-cache');
		}
		if ($puppeteerCache !== '') {
			$env['PUPPETEER_CACHE_DIR'] = $puppeteerCache;
		}

		$env['CHROME_CRASHPAD_DISABLED'] = '1';

		if (filter_var(env('SCRAPER_CHROME_SINGLE_PROCESS', false), FILTER_VALIDATE_BOOLEAN)) {
			$env['SCRAPER_CHROME_SINGLE_PROCESS'] = 'true';
		}
		$chromeUserData = trim((string) env('SCRAPER_CHROME_USER_DATA_DIR', ''));
		if ($chromeUserData !== '') {
			$env['SCRAPER_CHROME_USER_DATA_DIR'] = $chromeUserData;
		}

		$headlessOpt = trim((string) env('SCRAPER_CHROME_HEADLESS', ''));
		if ($headlessOpt !== '') {
			$env['SCRAPER_CHROME_HEADLESS'] = $headlessOpt;
		}
		if (filter_var(env('SCRAPER_CHROME_PIPE', false), FILTER_VALIDATE_BOOLEAN)) {
			$env['SCRAPER_CHROME_PIPE'] = 'true';
		}
		$puppeteerExe = trim((string) env('PUPPETEER_EXECUTABLE_PATH', ''));
		if ($puppeteerExe !== '') {
			$env['PUPPETEER_EXECUTABLE_PATH'] = $puppeteerExe;
		}
		$scraperChromeExe = trim((string) env('SCRAPER_CHROME_EXECUTABLE', ''));
		if ($scraperChromeExe !== '') {
			$env['SCRAPER_CHROME_EXECUTABLE'] = $scraperChromeExe;
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
		$href = trim($href);
		if ($href === '' || $href === '#') {
			return $base;
		}

		if (preg_match('/^https?:/i', $href)) {
			return $href;
		}

		// Protocol-relative
		if (str_starts_with($href, '//')) {
			$baseScheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

			return $baseScheme . ':' . $href;
		}

		// mailto:, tel:, sms:, data:, etc. — must not be joined to https://host/
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
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
