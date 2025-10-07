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
		// Normalize pdfLinks to array
		if (is_string($pdfLinks) && !empty($pdfLinks)) {
			$pdfLinks = [ ['PDF_LINK' => $pdfLinks] ];
		}

		// 🧠 No API → fallback to URL-based title
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

		// 🧩 Build system + user prompts
		$promptSystem = <<<SYS
You are a precise assistant that extracts government or school district bid details from web pages.
Always return output in strict JSON format.
If a PDF link is provided, include the PDF URL in the DESCRIPTION field as:
"See document: [PDF URL]"
Do not summarize or read the PDF content — only include the link.
SYS;

		$promptUser = [
			'instructions' => 'Extract open bids (ignore closed or canceled ones). Respond only with JSON.',
			'URL' => $URL,
			'pdf_links' => array_column($pdfLinks, 'PDF_LINK'),
			'text_excerpt' => mb_substr($text ?? '', 0, 8000),
			'html_excerpt' => mb_substr($html ?? '', 0, 8000),
			'example_format' => [
				'bids' => [
					[
						'TITLE' => 'Bid title',
						'ENDDATE' => 'YYYY-MM-DD or empty if not found',
						'NAICSCODE' => 'NAICS code or empty',
						'DESCRIPTION' => 'See document: https://example.com/docs/invitation1234.pdf'
					]
				]
			]
		];

		// 🧠 Build OpenAI request
		$body = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $promptSystem],
				['role' => 'user', 'content' => json_encode($promptUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
			],
			'response_format' => ['type' => 'json_object'],
			'temperature' => 0,
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

		// 🧩 Normalize bids safely
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
