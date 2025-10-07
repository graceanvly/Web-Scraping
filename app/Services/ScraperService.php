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
		$pdfText = '';

		// 🧩 1. Direct PDF URL
		if (preg_match('/\.pdf($|\?)/i', $url)) {
			return $this->fetchPdf($url);
		}

		// 🧩 2. Fetch HTML content
		$bestHtml = '';
		$bestText = '';
		$finalUrl = $url;

		try {
			$response = $this->httpClient->get($url);
			$bestHtml = (string) $response->getBody();
			$bestText = $this->htmlToText($bestHtml);
		} catch (RequestException $e) {
			Log::warning('SCRAPER FETCH ERROR', ['url' => $url, 'error' => $e->getMessage()]);
		}

		// 🧩 3. Find all PDF-related bids
		$pdfBids = $this->findPdfLink($bestHtml, $url);

		if (!empty($pdfBids)) {
			Log::info('PDF LINKS FOUND', ['url' => $url, 'count' => count($pdfBids)]);

			// Optionally fetch text from the first one, just for completeness
			$firstPdf = $pdfBids[0]['PDF_LINK'] ?? null;
			if ($firstPdf) {
				$pdfResult = $this->fetchPdf($firstPdf);
				if (!empty($pdfResult['text'])) {
					$pdfText = $pdfResult['text'];
				}
			}
		} else {
			Log::info('NO PDF LINK FOUND', ['url' => $url]);
		}

		// 🧩 4. Fallback: use Browsershot if text is empty
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
		]);

		return [
			'final_url' => $finalUrl,
			'html' => $bestHtml,
			'text' => $bestText,
			'pdf_bids' => $pdfBids, // 🧠 return multiple PDFs with titles
			'pdf_text' => $pdfText, // keep for compatibility
			'pdf_link' => $pdfLink ?? null,
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

		// fallback: raw .pdf urls in text
		if (empty($bids) && preg_match_all('/https?:\/\/[^\s"\']+\.pdf/i', $html, $matches)) {
			foreach ($matches[0] as $pdfUrl) {
				$bids[] = [
					'TITLE' => $this->extractTitleFromUrl($pdfUrl),
					'PDF_LINK' => $pdfUrl,
				];
			}
		}

		return $bids;
	}

	/**
	 * Produce a readable title from a URL or filename.
	 */
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
