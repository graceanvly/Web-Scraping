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

	public function extract(string $URL, string $html, string $text, array|string $pdfLinks = []): array
	{
		// 🧩 Normalize pdfLinks
		if (is_string($pdfLinks) && !empty($pdfLinks)) {
			$pdfLinks = [['PDF_LINK' => $pdfLinks]];
		}

		// 🧩 Step 1: Extract text directly from any accessible PDFs
		$pdfTexts = [];
		foreach ($pdfLinks as $pdf) {
			if (empty($pdf['PDF_LINK']))
				continue;

			try {
				$tempFile = tempnam(sys_get_temp_dir(), 'bidpdf_');
				// 🧹 Clean up any hidden whitespace or newlines in PDF link
				$pdfUrl = preg_replace('/\s+/', '', $pdf['PDF_LINK']);
				$pdfUrl = str_replace(' ', '%20', $pdfUrl); // escape spaces just in case

				$this->httpClient->request('GET', $pdfUrl, ['sink' => $tempFile]);

				$parser = new Parser();
				$pdfObj = $parser->parseFile($tempFile);
				$pdfText = trim($pdfObj->getText());

				// 🧹 Normalize excessive line breaks (fixes large paragraph gaps)
				$pdfText = preg_replace("/\n{3,}/", "\n\n", $pdfText);

				if (!empty($pdfText)) {
					$pdfTexts[] = [
						'PDF_LINK' => $pdf['PDF_LINK'],
						'PDF_TEXT' => mb_substr($pdfText, 0, 20000),
					];
				}

				@unlink($tempFile);
			} catch (\Throwable $e) {
				$pdfTexts[] = [
					'PDF_LINK' => $pdf['PDF_LINK'],
					'PDF_TEXT' => '',
				];
			}
		}

		// Combine all parsed PDF text
		$fullPdfText = implode("\n\n", array_column($pdfTexts, 'PDF_TEXT'));

		// 🧩 If there’s no API key, just return the PDF content directly
		if (!empty($fullPdfText)) {
			return [
				'bids' => [
					[
						'TITLE' => $this->extractTitleFromUrl($URL),
						'ENDDATE' => '',
						'NAICSCODE' => '',
						'DESCRIPTION' => $fullPdfText,
					],
				],
			];
		}

		// 🧠 Enhanced system prompt
		$promptSystem = <<<SYS
			You are an expert bid data extraction assistant.
			Your job is to extract all open/active bids and include the *full* content of each bid’s PDF.

			Rules:
			1. Extract only open or active bids.
			2. If PDF text is provided, do **not** summarize or shorten any text.
			Copy the full readable text from the PDF into the DESCRIPTION field exactly as it appears.
			3. If multiple PDFs exist, merge their text into DESCRIPTION.
			4. Determine the most likely NAICS code based on title or content.
			Reference: https://www.census.gov/naics/?input=51&chart=2022
			5. Output valid JSON only, in this format:

			{
			"bids": [
				{
				"TITLE": "Exact title",
				"ENDDATE": "YYYY-MM-DD or empty",
				"NAICSCODE": "541512",
				"DESCRIPTION": "Full unedited text from the bid’s PDF"
				}
			]
			}
			SYS;

		// 🧠 User content (page + PDF info)
		$promptUser = [
			'instructions' => 'Extract open bid(s) from this page. Include the full unedited PDF content in DESCRIPTION. Respond only with JSON.',
			'URL' => $URL,
			'pdf_links' => array_column($pdfLinks, 'PDF_LINK'),
			'pdf_text' => $fullPdfText,
			'text_excerpt' => mb_substr($text ?? '', 0, 8000),
			'html_excerpt' => mb_substr($html ?? '', 0, 8000),
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

		// 🧩 Make OpenAI request
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

		// 🧩 Normalize output
		$normalized = array_map(function ($bid) use ($URL, $fullPdfText) {
			$desc = $fullPdfText ?: ($bid['DESCRIPTION'] ?? 'No PDF or description found.');
			// 🧹 Normalize newlines in AI-generated text as well
			$desc = preg_replace("/\n{3,}/", "\n\n", $desc);

			return [
				'TITLE' => $bid['TITLE'] ?? $this->extractTitleFromUrl($URL),
				'ENDDATE' => $bid['ENDDATE'] ?? '',
				'NAICSCODE' => $bid['NAICSCODE'] ?? '',
				'DESCRIPTION' => $desc,
			];
		}, $bids);

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
}
