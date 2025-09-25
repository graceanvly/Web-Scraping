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
			'timeout' => 30,
		]);
		$this->apiKey = (string) ($_ENV['OPENAI_API_KEY'] ?? '');
		$this->model = (string) ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini');
	}

	public function extract(string $url, string $html, string $text): array
	{
		if (empty($this->apiKey)) {
			return [
				'title' => $this->extractTitleFromUrl($url),
				'end_date' => null,
				'naics_code' => null,
				'other_data' => null,
				'_warning' => 'OPENAI_API_KEY is not set',
			];
		}

		// If content is blocked or minimal, provide basic extraction
		if (strlen($text) < 500 || strpos($text, 'Client Challenge') !== false) {
			return [
				'title' => $this->extractTitleFromUrl($url),
				'end_date' => null,
				'naics_code' => null,
				'other_data' => ['note' => 'Content may be blocked by anti-bot protection'],
				'_warning' => 'Limited content due to bot detection',
			];
		}

		$promptSystem = 'You are a precise data extraction assistant for government bidding pages. Extract required fields in strict JSON. Look for bid titles, proposal numbers, due dates, and NAICS codes.';
		$promptUser = [
			'instructions' => 'Extract the following fields from this bidding/procurement page. Look for bid titles, proposal numbers, due dates, and NAICS codes. Return ISO8601 date for end_date if present. For the title field, prioritize the most complete and descriptive title available - this should include the full project name, any specific locations, school names, or detailed descriptions. Examples of good titles: "Lease-Leaseback Construction Services (Alberta Martone, James Marshall, Catherine Everett and Rose Avenue Elementary Schools Expanded Learning Wellness Center Building and Site Improvements Projects)" or "Professional Engineering Services for Downtown Infrastructure Rehabilitation Phase II". Avoid generic titles like "Construction Services" or "Professional Services" - extract the full descriptive title when available.',
			'fields' => ['title', 'end_date', 'naics_code', 'other_data'],
			'url' => $url,
			'text_excerpt' => mb_substr($text, 0, 6000),
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

		return [
			'title' => $data['title'] ?? $this->extractTitleFromUrl($url),
			'end_date' => $data['end_date'] ?? null,
			'naics_code' => $data['naics_code'] ?? null,
			'other_data' => $data['other_data'] ?? null,
		];
	}

	private function extractTitleFromUrl(string $url): string
	{
		$path = parse_url($url, PHP_URL_PATH);
		$segments = explode('/', trim($path, '/'));
		
		// Look for segments that might contain descriptive information
		$descriptiveSegments = array_filter($segments, function($segment) {
			return strlen($segment) > 5 && !is_numeric($segment);
		});
		
		if (!empty($descriptiveSegments)) {
			// Use the most descriptive segment
			$title = end($descriptiveSegments);
		} else {
			$title = end($segments);
		}
		
		// Convert URL segment to readable title
		$title = str_replace(['-', '_', '%20'], ' ', $title);
		$title = urldecode($title);
		$title = ucwords(strtolower($title));
		
		return $title ?: 'Bidding Page';
	}
}
