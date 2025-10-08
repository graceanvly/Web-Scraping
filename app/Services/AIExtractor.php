<?php

namespace App\Services;

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
		// Normalize pdfLinks
		if (is_string($pdfLinks) && !empty($pdfLinks)) {
			$pdfLinks = [['PDF_LINK' => $pdfLinks]];
		}

		if (empty($this->apiKey)) {
			return [
				'bids' => array_map(fn($pdf) => [
					'TITLE' => $pdf['TITLE'] ?? $this->extractTitleFromUrl($URL),
					'ENDDATE' => '',
					'NAICSCODE' => '',
					'DESCRIPTION' => "See document: {$pdf['PDF_LINK']}",
				], $pdfLinks ?: [
						[
							'TITLE' => $this->extractTitleFromUrl($URL),
							'ENDDATE' => '',
							'NAICSCODE' => '',
							'DESCRIPTION' => '',
						]
					]),
			];
		}

		// 🧠 Enhanced system prompt
		$promptSystem = <<<SYS
			You are an expert bid data extraction assistant.

			Your task:
			1. Extract only open/active bids (ignore closed or canceled).
			2. If a bid has a detail PDF, open it and **read its entire content**.
			- Extract and include **the whole content text** from the PDF.
			3. Include only the PDF(s) belonging to each specific bid.
			4. Determine the most likely NAICS code for each bid based on its title, description, or PDF content.
			Reference: https://www.census.gov/naics/?input=51&chart=2022
			Examples:
			- "IT Services", "Software Development", "Website" → 541512
			- "Construction", "Renovation", "Repair" → 236220
			- "Janitorial", "Cleaning" → 561720
			- "Office Supplies" → 424120
			- "Consulting" → 541611
			Leave NAICSCODE blank if you cannot infer it confidently.
			5. Always output valid JSON in this format:

			{
			"bids": [
				{
				"TITLE": "Exact title",
				"ENDDATE": "YYYY-MM-DD or empty",
				"NAICSCODE": "541512",
				"DESCRIPTION": "Full text from the bid detail PDF document."
				}
			]
			}
			SYS;

		$promptUser = [
			'instructions' => 'Extract open bids and include text content from the related bid PDF. Respond only with JSON.',
			'URL' => $URL,
			'pdf_links' => array_column($pdfLinks, 'PDF_LINK'),
			'text_excerpt' => mb_substr($text ?? '', 0, 8000),
			'html_excerpt' => mb_substr($html ?? '', 0, 8000),
			'example_format' => [
				'bids' => [
					[
						'TITLE' => 'Bid title',
						'ENDDATE' => 'YYYY-MM-DD',
						'NAICSCODE' => '541512',
						'DESCRIPTION' => 'Full text from the bid detail PDF document.'
					]
				]
			]
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

		// Normalize
		$normalized = array_map(function ($bid) use ($URL, $pdfLinks) {
			return [
				'TITLE' => $bid['TITLE'] ?? $this->extractTitleFromUrl($URL),
				'ENDDATE' => $bid['ENDDATE'] ?? '',
				'NAICSCODE' => $bid['NAICSCODE'] ?? '',
				'DESCRIPTION' => $bid['DESCRIPTION']
					?? (!empty($pdfLinks)
						? "See document: " . implode(', ', array_column($pdfLinks, 'PDF_LINK'))
						: ''),
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
