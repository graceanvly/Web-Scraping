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

	// app/Services/ScraperService.php
	public function __construct()
	{
		$this->httpClient = new Client([
			'timeout' => 90,
			'connect_timeout' => 20,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 ...',
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
			// force IPv4 + TLS1.2 to avoid handshake issues
			'curl' => [
				CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
				CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
			],
		]);
	}


	public function fetch(string $url): array
	{
		$pdfText = '';
		$bidPages = [];

		// 1. If direct PDF URL
		if (preg_match('/\.pdf($|\?)/i', $url)) {
			return $this->fetchPdf($url);
		}

		// 2. Fetch HTML content
		$bestHtml = '';
		$bestText = '';
		$finalUrl = $url;

		try {
			$response = $this->httpClient->get($url);
		} catch (RequestException $e) {
			Log::warning('SCRAPER FETCH ERROR', ['url' => $url, 'error' => $e->getMessage()]);
			if (str_starts_with($url, 'https://')) {
				$httpUrl = 'http://' . substr($url, 8);
				try {
					$response = $this->httpClient->get($httpUrl);
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

		// 3. Find PDF links (bids)
		$pdfBids = $this->findPdfLink($bestHtml, $url);

		if (!empty($pdfBids)) {
			Log::info('PDF LINKS FOUND', ['url' => $url, 'count' => count($pdfBids)]);

			$pdfTexts = [];
			foreach ($pdfBids as $bid) {
				if (!empty($bid['PDF_LINK'])) {
					$pdfResult = $this->fetchPdf($bid['PDF_LINK']);
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
		$detailLinks = $this->findBidDetailLinks($bestHtml, $url);
		$detailLinks = array_slice($detailLinks, 0, 8); // cap depth
		foreach ($detailLinks as $detail) {
			try {
				$pageHtml = '';
				$pageText = '';
				$pagePdfText = '';
				$pagePdfLinks = [];

				$response = $this->httpClient->get($detail['URL']);
				$pageHtml = (string) $response->getBody();
				$pageText = $this->htmlToText($pageHtml);
				$pagePdfLinks = $this->findPdfLink($pageHtml, $detail['URL']);

				$pdfTexts = [];
				foreach ($pagePdfLinks as $link) {
					if (!empty($link['PDF_LINK'])) {
						$pdfResult = $this->fetchPdf($link['PDF_LINK']);
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
			'blocked' => false,
		];
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

	private function fetchPdf(string $url): array
	{
		try {
			$response = $this->httpClient->get($url, ['stream' => true]);
			$tempFile = tmpfile();
			$meta = stream_get_meta_data($tempFile);
			file_put_contents($meta['uri'], $response->getBody()->getContents());

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
}
