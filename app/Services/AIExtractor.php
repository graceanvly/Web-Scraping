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
			'timeout' => 90,          // allow longer completion time
			'connect_timeout' => 30,  // avoid infinite waits on connect
		]);
		$this->apiKey = (string) ($_ENV['OPENAI_API_KEY'] ?? '');
		$this->model = (string) ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini');
	}

	public function extract(string $url, string $html, string $text): array
	{
		if (empty($this->apiKey)) {
			return [
				'bids' => [
					[
						'title' => $this->extractTitleFromUrl($url),
						'end_date' => '',
						'naics_code' => '',
						'other_data' => ['note' => 'OPENAI_API_KEY is not set'],
					]
				]
			];
		}

		if (strlen($text) < 500 || strpos($text, 'Client Challenge') !== false) {
			return [
				'bids' => [
					[
						'title' => $this->extractTitleFromUrl($url),
						'end_date' => '',
						'naics_code' => '',
						'other_data' => ['note' => 'Content may be blocked by anti-bot protection'],
					]
				]
			];
		}

		$promptSystem = 'You are a precise data extraction assistant or agent for government and procurement web pages.
			Extract only bids that are explicitly marked as "Open" (active).
			Ignore all bids marked Closed, Pending, Canceled, or Expired.
			Also ignore any bid whose due date is already earlier than today.
			Do not return generic headers or page titles like "Open Bids And Proposals".
			Always output in strict JSON format.';


		$promptUser = [
			'instructions' => 'From the provided bidding/procurement page text, extract only bids that have status "Open" 
			AND whose due date has not yet passed (>= today).
			Output strictly as JSON in this structure:
			{
			"bids": [
				{
				"title": "...",
				"end_date": "...",
				"naics_code": "...",
				"other_data": { ... }
				}
			]
			}

			Rules:
			- Include only bids where status = "Open" AND due date is today or in the future.
			- Skip any bid with status Closed, Pending, or Cancelled.
			- Skip any bid with a due date earlier than today.
			- title: Use the most descriptive project/bid title.
			- end_date: ISO8601 (YYYY-MM-DDTHH:MM:SS) or empty string if missing.
			- naics_code: empty string if not found.
			- other_data: include **all other important bid-related information** found (e.g. solicitation number, type, category, agency, contact info, response method, status, budget, documents, description, etc.). 
			Do not add fields if they are not present in the page.
			- For documents/attachments: extract all links (<a> tags) that point to files 
			(PDF, DOCX, XLS, etc.) or look like bid-related documents. 
			Capture both the link text and the href.
			- Never return null — always use empty string or empty object.
			',
			'url' => $url ?? '',
			'text_excerpt' => mb_substr($text ?? '', 0, 20000),
			'html_excerpt' => mb_substr($html, 0, 20000),
		];



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

		// Ensure structure
		$bids = $data['bids'] ?? [];

		// Normalize: no nulls
		$normalized = array_map(function ($bid) use ($url) {
			return [
				'title' => $bid['title'] ?? $this->extractTitleFromUrl($url),
				'end_date' => $bid['end_date'] ?? '',
				'naics_code' => $bid['naics_code'] ?? '',
				'other_data' => $bid['other_data'] ?? new \stdClass(),
			];
		}, $bids);

		return ['bids' => $normalized];
	}

	private function extractTitleFromUrl(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$segments = explode('/', trim($path, '/'));

		$descriptiveSegments = array_filter($segments, function ($segment) {
			return strlen($segment) > 5 && !is_numeric($segment);
		});

		$title = !empty($descriptiveSegments) ? end($descriptiveSegments) : end($segments);

		$title = str_replace(['-', '_', '%20'], ' ', $title);
		$title = urldecode($title);
		$title = ucwords(strtolower($title));

		return $title ?: 'Bidding Page';
	}
}
