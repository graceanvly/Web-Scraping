<?php

namespace App\Services;

use Smalot\PdfParser\Parser;
use GuzzleHttp\Client;

class AIExtractor
{
	private Client $httpClient;
	private string $apiKey;
	private string $model;

	public function __construct()
	{
		$this->httpClient = new Client([
			'timeout' => 90,
			'connect_timeout' => 30,
		]);

		$this->apiKey = (string) ($_ENV['OPENAI_API_KEY'] ?? '');
		$this->model = (string) ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini');
	}

	public function extract(string $URL, string $html, string $text, array|string $pdfLinks = [], string $pdfText = '', array $bidPages = []): array
	{
		// Normalize pdfLinks input
		if (is_string($pdfLinks) && !empty($pdfLinks)) {
			$pdfLinks = [['PDF_LINK' => $pdfLinks]];
		}

		// If scraper hasn't parsed the PDFs yet, try to parse them here to feed the AI
		$parsedPdfTexts = [];
		if (empty($pdfText)) {
			foreach ($pdfLinks as $pdf) {
				if (empty($pdf['PDF_LINK'])) {
					continue;
				}

				try {
					$tempFile = tempnam(sys_get_temp_dir(), 'bidpdf_');
					$pdfUrl = preg_replace('/\s+/', '', $pdf['PDF_LINK']);
					$pdfUrl = str_replace(' ', '%20', $pdfUrl);

					$this->httpClient->request('GET', $pdfUrl, ['sink' => $tempFile]);

					$parser = new Parser();
					$pdfObj = $parser->parseFile($tempFile);
					$pdfTextRaw = trim($pdfObj->getText());
					$pdfTextRaw = preg_replace("/\n{3,}/", "\n\n", $pdfTextRaw);

					if (!empty($pdfTextRaw)) {
						$parsedPdfTexts[] = [
							'PDF_LINK' => $pdf['PDF_LINK'],
							'PDF_TEXT' => mb_substr($pdfTextRaw, 0, 20000),
						];
					}

					@unlink($tempFile);
				} catch (\Throwable $e) {
					$parsedPdfTexts[] = [
						'PDF_LINK' => $pdf['PDF_LINK'],
						'PDF_TEXT' => '',
					];
				}
			}
		}

		$fullPdfText = $pdfText ?: implode("\n\n", array_column($parsedPdfTexts, 'PDF_TEXT'));
		$fullPdfText = preg_replace("/\n{3,}/", "\n\n", (string) $fullPdfText);

		// Fallback when no API key is configured: at least return the text we have
		if (empty($this->apiKey)) {
			return [
				'bids' => [
					[
						'TITLE' => $this->extractTitleFromUrl($URL),
						'ENDDATE' => '',
						'NAICSCODE' => '',
						'DESCRIPTION' => $fullPdfText ?: ($text ?: 'No description or PDF link found.'),
					],
				],
			];
		}

		$promptSystem = <<<SYS
You are an expert bid data extraction assistant.
You receive a listing page plus detail pages that were "clicked" from bid titles and any PDF text already retrieved for those bids.
Extract all open/active bids and capture the full contact and schedule details.

For each bid you return, include:
- TITLE (exact title from detail page or listing)
- ENDDATE (YYYY-MM-DD when present)
- NAICSCODE (best guess if not explicitly provided)
- DESCRIPTION (A labeled block that includes: Bid Title; Description / Scope / Specification; End Date / Due Date; Pre-bid Meeting Date; Questions relating to the Bid; Contact Person with roles (Purchasing Agent, Finance Officer, Bid Clerk, County/City/Town Clerk, Officer in Charge, School Administrator, District Engineer, Commissioner, Accounting if applicable); Phone Number; Email Address; Mailing Address; Physical Address; Correct geographic location/state and category.)

Rules:
1) Use PDF text when present; otherwise use clicked detail page text and listing text.
2) If a value is missing, write "Not provided" for that label instead of leaving it blank.
3) Respond with strict JSON only in the format: {"bids":[{...}]} with the fields above.
SYS;

		$promptUser = [
			'instructions' => 'Use listing, clicked bid pages, and PDF text to extract open bids.',
			'URL' => $URL,
			'pdf_links' => array_column($pdfLinks, 'PDF_LINK'),
			'pdf_text_excerpt' => mb_substr($fullPdfText ?? '', 0, 18000),
			'listing_text_excerpt' => mb_substr($text ?? '', 0, 8000),
			'listing_html_excerpt' => mb_substr($html ?? '', 0, 8000),
			'bid_pages' => array_map(function ($page) {
				return [
					'url' => $page['url'] ?? '',
					'title' => $page['title'] ?? '',
					'text_excerpt' => mb_substr($page['text'] ?? '', 0, 6000),
					'html_excerpt' => mb_substr($page['html'] ?? '', 0, 4000),
					'pdf_links' => array_column($page['pdf_links'] ?? [], 'PDF_LINK'),
					'pdf_text_excerpt' => mb_substr($page['pdf_text'] ?? '', 0, 8000),
				];
			}, $bidPages),
		];

		$body = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $promptSystem],
				['role' => 'user', 'content' => json_encode($promptUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
			],
			'response_format' => ['type' => 'json_object'],
			'temperature' => 0.1,
		];

		$response = $this->httpClient->post('https://api.openai.com/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($body),
		]);

		$json = json_decode((string) $response->getBody(), true);
		$content = $json['choices'][0]['message']['content'] ?? '{}';
		$data = json_decode($content, true);

		$bids = $data['bids'] ?? [];

		$normalized = array_map(function ($bid) use ($URL, $fullPdfText, $text) {
			$desc = $bid['DESCRIPTION'] ?? '';
			if (empty($desc)) {
				$desc = $fullPdfText ?: ($text ?: 'No PDF or description found.');
			}
			$desc = $this->formatDescription($desc);

			$title = $bid['TITLE'] ?? $this->extractTitleFromUrl($URL);
			if (is_array($title)) {
				$title = implode(' ', $title);
			}

			$endDate = $bid['ENDDATE'] ?? '';
			if (is_array($endDate)) {
				$endDate = implode(' ', $endDate);
			}

			$naics = $bid['NAICSCODE'] ?? '';
			if (is_array($naics)) {
				$naics = implode(' ', $naics);
			}

			return [
				'TITLE' => (string) $title,
				'ENDDATE' => (string) $endDate,
				'NAICSCODE' => (string) $naics,
				'DESCRIPTION' => $desc,
			];
		}, $bids);

		if (empty($normalized)) {
			$normalized[] = [
				'TITLE' => $this->extractTitleFromUrl($URL),
				'ENDDATE' => '',
				'NAICSCODE' => '',
				'DESCRIPTION' => $fullPdfText ?: ($text ?: 'No PDF or description found.'),
			];
		}

		return ['bids' => $normalized];
	}

	private function extractTitleFromUrl(string $URL): string
	{
		$path = parse_url($URL, PHP_URL_PATH);
		$segments = explode('/', trim($path, '/'));
		$descriptiveSegments = array_filter($segments, fn($seg) => strlen($seg) > 5 && !is_numeric($seg));

		$title = !empty($descriptiveSegments) ? end($descriptiveSegments) : end($segments);
		$title = str_replace(['-', '_', '%20'], ' ', $title);
		$title = urldecode($title);
		$title = ucwords(strtolower($title));

		return $title ?: 'Bidding Page';
	}

	/**
	 * Make description human readable by decoding JSON/arrays and formatting as labeled lines.
	 */
	private function formatDescription($desc): string
	{
		if (is_array($desc)) {
			return $this->flattenDescriptionArray($desc);
		}

		if (is_string($desc)) {
			$decoded = json_decode($desc, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				return $this->flattenDescriptionArray($decoded);
			}
			return preg_replace("/\n{3,}/", "\n\n", $desc);
		}

		return (string) $desc;
	}

	private function flattenDescriptionArray(array $data, string $indent = ''): string
	{
		$lines = [];
		foreach ($data as $key => $value) {
			$label = is_int($key) ? $key : $key;
			if (is_array($value)) {
				$lines[] = "{$indent}{$label}:";
				$lines[] = $this->flattenDescriptionArray($value, $indent . '  ');
				continue;
			}
			$lines[] = "{$indent}{$label}: {$value}";
		}
		return implode("\n", array_filter($lines));
	}
}
