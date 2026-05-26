<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Stream as GuzzleStream;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class AIExtractor
{
	private Client $httpClient;

	private Client $httpClientRewrite;

	/** Lazily-built client using PHP streams (no ext-curl) when cURL rejects Guzzle opts on some hosts */
	private ?Client $openAiStreamFallbackClient = null;

	private string $apiKey;

	private string $model;

	public function __construct()
	{
		$timeoutExtract = max(30.0, (float) config('services.openai.http_timeout', 300));
		$timeoutRewrite = max(30.0, (float) config('services.openai.http_timeout_rewrite', 120));
		$connectTimeout = max(5.0, (float) config('services.openai.http_connect_timeout', 30));

		$forceOpenAiStreams = (bool) config('services.openai.http_use_stream', true);

		$extractOpts = [
			'timeout' => $timeoutExtract,
			'connect_timeout' => $connectTimeout,
		];
		$rewriteOpts = [
			'timeout' => min($timeoutExtract, $timeoutRewrite),
			'connect_timeout' => $connectTimeout,
		];
		if ($forceOpenAiStreams) {
			$extractOpts['handler'] = HandlerStack::create(new StreamHandler());
			$rewriteOpts['handler'] = HandlerStack::create(new StreamHandler());
		}

		$this->httpClient = new Client($extractOpts);
		$this->httpClientRewrite = new Client($rewriteOpts);

		$this->apiKey = (string) config('services.openai.key', '');
		$this->model = (string) config('services.openai.model', 'gpt-4o-mini');
	}

	public function extract(string $URL, string $html, string $text, array|string $pdfLinks = [], string $pdfText = '', array $bidPages = [], array $options = []): array
	{
		// Normalize pdfLinks input
		if (is_string($pdfLinks) && !empty($pdfLinks)) {
			$pdfLinks = [['PDF_LINK' => $pdfLinks]];
		}

		// PDF parsing is handled by ScraperService with size and timeout limits.
		// Re-parsing here can exhaust memory on large procurement PDFs.
		$parsedPdfTexts = [];
		if (empty($pdfText)) {
			foreach ($pdfLinks as $pdf) {
				if (empty($pdf['PDF_LINK'])) {
					continue;
				}

				$parsedPdfTexts[] = [
					'PDF_LINK' => $pdf['PDF_LINK'],
					'PDF_TEXT' => '',
				];
			}
		}

		$fullPdfText = $pdfText ?: implode("\n\n", array_column($parsedPdfTexts, 'PDF_TEXT'));
		$fullPdfText = preg_replace("/\n{3,}/", "\n\n", (string) $fullPdfText);

		if (empty($this->apiKey)) {
			throw new \RuntimeException('AI is not configured: the OPENAI_API_KEY environment variable is missing or empty. Please set it in the .env file.');
		}

		$promptSystem = <<<SYS
You are an expert bid data extraction assistant.
You receive a listing page, browser interaction states gathered after clicking bid-relevant buttons/tabs/dropdowns, detail pages clicked from bid titles, and any PDF text already retrieved for those bids.
Extract all open/active bids and capture the full operational details needed to understand and respond to each bid.

For each bid you return, include:
- TITLE (exact title from detail page or listing)
- ENDDATE (YYYY-MM-DD when present)
- NAICSCODE (best guess if not explicitly provided)
- URL (detail page URL or PDF URL where the bid was found; otherwise the listing URL)
- BID_CATEGORY (short procurement category label that best matches the work or commodity, e.g. Construction, IT Services, Professional Services, Supplies, Equipment, Services General — suitable for matching a master category list)
- LOCATION_STATE (US state as a 2-letter postal abbreviation when the bid is clearly in the US, e.g. FL for Florida; otherwise full state/province name or "Not provided")
- POSTING_ENTITY (exactly one of: government, private_company, uncertain). Classify the **immediate posting source** — who published this solicitation to bidders — not necessarily the project's ultimate owner.
  • **government**: Posted on an official agency/procurement site (e.g. .gov portals, CivicPlus, DemandStar/Jaggaer gov modules, SAM-style pages, county/city/school/university solicitation pages when **the agency** is the buyer of record issuing the solicitation.
  • **private_company**: A **business** publishes the outreach (general contractor seeking subs/suppliers; construction firm/supplier solicitation; subcontractor/supplier bids "to the GC"; trade-journal or vendor-hosted pages where a **company** invites bids for its vendor chain even if the work is ultimately for a public owner.
  • **uncertain**: If you truly cannot tell.
- DESCRIPTION (**The full long-form solicitation text.** This must be the complete project narrative: scope / specifications / solicitation body / "PROJECT DESCRIPTION" / invitation text from the detail page or PDF — not merely a labeled summary of Title, Status, End Date, state, or category. Where the pasted content includes a block marked "--- PRIMARY PROJECT DESCRIPTION ---", use that narrative in full (or as much as fits) as the core of DESCRIPTION, preserving paragraph breaks. You may prepend a short "**Metadata:**" subsection with solicitation number, agency, contacts, deadlines, addresses, bonding/insurance, submission instructions ONLY after the narrative, or weave those facts into prose — but DO NOT omit the narrative in favor of a short field list when the narrative exists on the page or in PRIMARY PROJECT DESCRIPTION.)
  **After the narrative**, when email addresses, phone numbers, or fax numbers appear anywhere in the sources for bid submission, questions, estimator/purchasing contacts, or outreach coordinators, append a clearly labeled **Contact** section so the text includes at least:
  Email: ...
  Phone: ...
  Fax: ... (only if present)
  List every distinct email/phone that is clearly relevant to responding to this bid (omit lines only when that type of contact truly does not appear). If the **Contact** block would duplicate the exact same lines already present in the narrative **as labeled contact fields**, you may skip repeating them.

- CONTACT_EMAIL (single **best** email for bid questions or submissions: estimator@, bids@, purchasing@, or the explicitly labeled submission address. Use empty string "" if no email appears anywhere.)
- ISSUING_ORGANIZATION (concise issuing buyer / procurement unit / company **name** whose entity record might be matched in a master entity list — e.g. "City of Miami", "State of Vermont DOT", "ACME Constructors LLC"; use "Not provided" when unknown.)
- ISSUING_ADDRESS (mailing/street/office address shown for the **issuing agency or contracting office**: building, street line(s), city, state/province, postal code when visibly part of solicitation / contact boilerplate — not merely the vague project geography unless labeled as departmental address; use "Not provided" when none appears.)
- ISSUING_CONTACT_PHONE (voice phone number(s) for questions or procurement for that issuing office — digits, extensions; separate multiple entries with commas; use "Not provided" or empty string "" when none appears.)

Rules:
1) Prefer the most specific source: PDF text first, then clicked detail pages and browser interaction states, then listing text.
2) If a value is missing, write "Not provided" for that label instead of leaving it blank. Exception: POSTING_ENTITY must always be exactly one of government, private_company, or uncertain — use uncertain when unknown; never use "Not provided" for POSTING_ENTITY. Use empty string "" for CONTACT_EMAIL when no email exists.
3) Respond with strict JSON only in the format: {"bids":[{...}]} including the fields above (TITLE, ENDDATE, NAICSCODE, URL, BID_CATEGORY, LOCATION_STATE, POSTING_ENTITY, CONTACT_EMAIL, ISSUING_ORGANIZATION, ISSUING_ADDRESS, ISSUING_CONTACT_PHONE, DESCRIPTION).
4) Browser interaction states are page snapshots captured after the scraper clicked likely controls. Use them to find content revealed by buttons, tabs, accordions, dropdowns, "view details", "documents", "attachments", and similar controls.
5) Do your best to find the bids: some sites require clicking links (e.g., "see all open bid opportunities") before listings appear. Only return actual bids; do not treat generic portal/home pages or unrelated content as bids.
6) Bonfire Hub / similar portals: listings often appear as a table of "opportunities" with titles, due dates, and status. Extract one bid per opportunity row when the portal shows open/public opportunities.
7) Trade journal / detail pages (e.g. Compliance News style): TITLE, ENDDATE, LOCATION_STATE, BID_CATEGORY should reflect structured fields where present; DESCRIPTION must still include the entire **PROJECT DETAILS / PROJECT DESCRIPTION** narrative, not replace it with Title + Status + state + commodity only. POSTING_ENTITY is usually **private_company** when a general contractor or firm is inviting sub-bids/supplier quotes, even if the AWARDING AGENCY is public.

SYS;

		$lim = $this->promptExcerptLimits($options);
		$promptUser = [
			'instructions' => 'Use listing, clicked bid pages, and PDF text to extract open bids.',
			'URL' => $URL,
			'pdf_links' => array_column($pdfLinks, 'PDF_LINK'),
			'pdf_text_excerpt' => mb_substr($fullPdfText ?? '', 0, $lim['pdf_text']),
			'listing_text_excerpt' => mb_substr($text ?? '', 0, $lim['listing_text']),
			'listing_html_excerpt' => mb_substr($html ?? '', 0, $lim['listing_html']),
			'bid_pages' => array_map(function ($page) use ($lim) {
				return [
					'url' => $page['url'] ?? '',
					'title' => $page['title'] ?? '',
					'source' => $page['source'] ?? 'detail_or_interaction_page',
					'interaction_type' => $page['interaction_type'] ?? '',
					'text_excerpt' => mb_substr($page['text'] ?? '', 0, $lim['bid_page_text']),
					'html_excerpt' => mb_substr($page['html'] ?? '', 0, $lim['bid_page_html']),
					'pdf_links' => array_column($page['pdf_links'] ?? [], 'PDF_LINK'),
					'pdf_text_excerpt' => mb_substr($page['pdf_text'] ?? '', 0, $lim['bid_page_pdf']),
				];
			}, $bidPages),
		];

		if (!empty($options['bulk_mode'])) {
			$budget = max(1000, (int) config('scraper.ai_bulk_bid_pages_total_budget_chars', 34000));
			$this->shrinkBulkBidPageExcerptsToBudget($promptUser['bid_pages'], $budget);
		}

		$userJson = json_encode($promptUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$combinedBidExcerpts = $this->combinedBulkBidPageExcerptChars($promptUser['bid_pages']);
		$bulkTimeout = !empty($options['bulk_mode'])
			? max(30.0, (float) config('services.openai.http_timeout_bulk_extract', 300))
			: null;

		Log::info('AI bid extract POST payload', [
			'url' => $URL,
			'bulk_mode' => !empty($options['bulk_mode']),
			'approx_user_json_bytes' => strlen((string) $userJson),
			'bid_pages_in_prompt' => count($promptUser['bid_pages'] ?? []),
			'combined_bid_page_excerpt_chars' => $combinedBidExcerpts,
			'guzzle_timeout_sec' => $bulkTimeout ?? max(30.0, (float) config('services.openai.http_timeout', 300)),
		]);

		$body = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $promptSystem],
				['role' => 'user', 'content' => $userJson],
			],
			'response_format' => ['type' => 'json_object'],
			'temperature' => 0.1,
		];

		$streamHeartbeatCb = is_callable($options['openai_heartbeat'] ?? null) ? $options['openai_heartbeat'] : null;
		$postBody = $body;
		if ($streamHeartbeatCb !== null) {
			$postBody['stream'] = true;
		}

		$requestOptions = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($postBody),
		];
		if ($bulkTimeout !== null) {
			$requestOptions['timeout'] = $bulkTimeout;
		}

		$extractWaitStarted = microtime(true);
		// CURL progress pings conflict with streamed responses — use streamed chunks + pulse below instead.
		$this->attachOpenAiExtractHeartbeatCurl(
			$requestOptions,
			$streamHeartbeatCb !== null ? null : ($options['openai_heartbeat'] ?? null),
			$extractWaitStarted
		);

		$chatHttpClient = $this->httpClient;
		$attemptedStreamAfterCurlSetoptFailure = false;
		while (true) {
			try {
				$reqEffective = $requestOptions;
				if ($streamHeartbeatCb !== null) {
					$reqEffective['stream'] = true;
					Log::info('AI bid extract OpenAI POST in flight — logs may dwell until streamed response opens', [
						'url' => $URL,
					]);
				}
				$response = $chatHttpClient->post('https://api.openai.com/v1/chat/completions', $reqEffective);
				break;
			} catch (\GuzzleHttp\Exception\ClientException $e) {
				$status = $e->getResponse()?->getStatusCode();
				$responseBody = (string) ($e->getResponse()?->getBody() ?? '');
				$errorData = json_decode($responseBody, true);
				$apiMessage = $errorData['error']['message'] ?? '';

				if ($status === 401) {
					throw new \RuntimeException('AI error: Invalid OpenAI API key. Please check the OPENAI_API_KEY value in your .env file.');
				}
				if ($status === 429) {
					throw new \RuntimeException('AI error: OpenAI rate limit or quota exceeded. ' . ($apiMessage ?: 'Please check your billing/usage at platform.openai.com.'));
				}
				if ($status === 404) {
					throw new \RuntimeException("AI error: Model \"{$this->model}\" not found. Please check the OPENAI_MODEL value in your .env file.");
				}
				throw new \RuntimeException('AI error: OpenAI returned HTTP ' . $status . '. ' . ($apiMessage ?: $e->getMessage()));
			} catch (\GuzzleHttp\Exception\ConnectException $e) {
				throw new \RuntimeException('AI error: Could not connect to OpenAI API. Please check your server\'s internet connection.');
			} catch (\Throwable $e) {
				if (!$attemptedStreamAfterCurlSetoptFailure && $this->isCurlSetoptArrayInvalidOptionsThrowable($e)) {
					Log::warning('OpenAI Chat Completions: curl_setopt rejected (PHP/libcurl vs Guzzle); retrying via stream handler.', [
						'url' => $URL,
					]);
					unset($requestOptions['curl']);
					$chatHttpClient = $this->openAiChatCompletionsStreamClient();
					$attemptedStreamAfterCurlSetoptFailure = true;
					continue;
				}

				$m = strtolower($e->getMessage());
				if (
					str_contains($m, 'timed out')
					|| str_contains($m, 'timeout')
					|| str_contains($m, 'curl error 28')
					|| str_contains($m, 'operation timed out')
					|| ($e instanceof \GuzzleHttp\Exception\TransferException)
				) {
					throw new \RuntimeException(
						'AI error: Extract stalled or timed out. Try increasing OPENAI_HTTP_TIMEOUT (or OPENAI_HTTP_TIMEOUT_BULK for scrape-all), '
						. 'or lower SCRAPER_AI_BULK_PAGES_TOTAL_CHARS / SCRAPER_AI_BULK_* excerpt sizes. Original: ' . $e->getMessage()
					);
				}
				throw new \RuntimeException('AI error: ' . $e->getMessage());
			}
		}

		if ($streamHeartbeatCb !== null) {
			Log::info('AI bid extract streamed from OpenAI (SSE progress pings)', ['url' => $URL]);
			$content = $this->drainOpenAiChatCompletionStream($response, $streamHeartbeatCb, $extractWaitStarted, $URL);
		} else {
			$json = json_decode((string) $response->getBody(), true);
			$content = is_array($json) ? ($json['choices'][0]['message']['content'] ?? '{}') : '{}';
		}
		if ($content === '') {
			$content = '{}';
		}
		$data = json_decode($content, true);
		if (!is_array($data)) {
			Log::warning('AI extract assistant JSON decode failed', ['url' => $URL, 'snippet' => mb_substr($content, 0, 400)]);
			$data = [];
		}

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

			$detailUrl = $bid['URL'] ?? '';
			if (is_array($detailUrl)) {
				$detailUrl = implode(' ', $detailUrl);
			}
			if (empty($detailUrl)) {
				$detailUrl = $URL;
			}

			$bidCategory = $bid['BID_CATEGORY'] ?? '';
			if (is_array($bidCategory)) {
				$bidCategory = implode(' ', $bidCategory);
			}

			$locationState = $bid['LOCATION_STATE'] ?? '';
			if (is_array($locationState)) {
				$locationState = implode(' ', $locationState);
			}

			$postingEntity = $this->normalizePostingEntity($bid['POSTING_ENTITY'] ?? null);

			$contactEmail = $this->normalizeContactEmailFromExtract($bid['CONTACT_EMAIL'] ?? $bid['EMAIL'] ?? null);

			$issuingOrg = $bid['ISSUING_ORGANIZATION'] ?? '';
			if (is_array($issuingOrg)) {
				$issuingOrg = implode(' ', $issuingOrg);
			}
			$issuingOrg = $this->normalizeIssuingOrganization((string) $issuingOrg);

			$issuingAddressRaw = $bid['ISSUING_ADDRESS'] ?? $bid['ENTITY_ADDRESS'] ?? '';
			if (is_array($issuingAddressRaw)) {
				$issuingAddressRaw = implode("\n", $issuingAddressRaw);
			}
			$issuingAddress = $this->normalizeIssuingContactSnippet((string) $issuingAddressRaw, true, 2500);

			$issuingPhoneRaw = $bid['ISSUING_CONTACT_PHONE'] ?? $bid['ENTITY_CONTACT_PHONE'] ?? $bid['ENTITY_PHONE'] ?? '';
			if (is_array($issuingPhoneRaw)) {
				$issuingPhoneRaw = implode(', ', array_map('trim', $issuingPhoneRaw));
			}
			$issuingPhone = $this->normalizeIssuingContactSnippet((string) $issuingPhoneRaw, false, 512);

			$desc = $this->appendIssuingEntityContactSuffix($desc, $issuingAddress, $issuingPhone);

			return [
				'TITLE' => (string) $title,
				'ENDDATE' => (string) $endDate,
				'NAICSCODE' => (string) $naics,
				'URL' => (string) $detailUrl,
				'BID_CATEGORY' => (string) $bidCategory,
				'LOCATION_STATE' => (string) $locationState,
				'POSTING_ENTITY' => $postingEntity,
				'CONTACT_EMAIL' => $contactEmail,
				'ISSUING_ORGANIZATION' => $issuingOrg,
				'DESCRIPTION' => $desc,
			];
		}, $bids);

		if (empty($normalized)) {
			$normalized[] = [
				'TITLE' => $this->extractTitleFromUrl($URL),
				'ENDDATE' => '',
				'NAICSCODE' => '',
				'URL' => $URL,
				'BID_CATEGORY' => '',
				'LOCATION_STATE' => '',
				'POSTING_ENTITY' => 'uncertain',
				'CONTACT_EMAIL' => '',
				'ISSUING_ORGANIZATION' => '',
				'DESCRIPTION' => $fullPdfText ?: ($text ?: 'No PDF or description found.'),
			];
		}

		return ['bids' => $normalized];
	}

	/**
	 * Use AI to rewrite raw scraped titles into clean, standardized format.
	 * Sends one Chat Completions request per invocation; bulk scrapes chunk large lists upstream.
	 */
	public function rewriteTitles(array $titles): array
	{
		if (empty($titles)) {
			return $titles;
		}
		if (empty($this->apiKey)) {
			Log::warning('AI title rewrite skipped: OPENAI_API_KEY is not configured.');
			return $titles;
		}

		Log::info('AI title rewrite starting', ['titles' => count($titles)]);
		$rewriteStarted = microtime(true);

		$system = <<<SYS
You are a bid title editor. Rewrite each opportunity title (government solicitations or private-sector bid postings — e.g. GC sub-bid invitations) for clarity.

Rules:
1) Remove bid/solicitation numbers from the beginning (e.g. "Bid 26121 - " or "RFP-2026-003:") but keep them if they are the ONLY identifier.
2) Use title case.
3) Remove redundant words like "Request for", "Invitation to Bid for", "Solicitation for" — just state what the bid is for.
4) Keep important qualifiers (location, department, year) if present.
5) Maximum 120 characters.
6) Do NOT change meaning or add information not in the original.
7) If a title is already clean, return it as-is.

Respond with strict JSON: {"titles": ["rewritten title 1", "rewritten title 2", ...]}
The output array MUST have the same number of items as the input, in the same order.
SYS;

		$body = [
			'model' => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => json_encode(['titles' => array_values($titles)], JSON_UNESCAPED_UNICODE)],
			],
			'response_format' => ['type' => 'json_object'],
			'temperature' => 0.1,
		];

		$rewriteRequest = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($body),
			'timeout' => min(
				max(30.0, (float) config('services.openai.http_timeout', 300)),
				max(30.0, (float) config('services.openai.http_timeout_rewrite', 120))
			),
		];

		try {
			$response = $this->executeOpenAiRewritePostWithCurlFallback($rewriteRequest);

			$json = json_decode((string) $response->getBody(), true);
			$content = $json['choices'][0]['message']['content'] ?? '{}';
			$data = json_decode($content, true);
			$rewritten = $data['titles'] ?? [];

			if (count($rewritten) === count($titles)) {
				Log::info('AI title rewrite finished', [
					'titles' => count($titles),
					'elapsed_sec' => round(microtime(true) - $rewriteStarted, 2),
				]);

				return array_values($rewritten);
			}

			Log::warning('AI title rewrite count mismatch', [
				'input' => count($titles),
				'output' => count($rewritten),
			]);
			return $titles;
		} catch (\Throwable $e) {
			Log::warning('AI title rewrite failed', ['error' => $e->getMessage()]);
			return $titles;
		}
	}

	/**
	 * Chat Completions over Guzzle’s PHP stream handler (no ext-curl). Used when curl_setopt_array fails on the default client.
	 */
	private function openAiChatCompletionsStreamClient(): Client
	{
		if ($this->openAiStreamFallbackClient instanceof Client) {
			return $this->openAiStreamFallbackClient;
		}

		$timeoutExtract = max(30.0, (float) config('services.openai.http_timeout', 300));
		$connectTimeout = max(5.0, (float) config('services.openai.http_connect_timeout', 30));

		$this->openAiStreamFallbackClient = new Client([
			'timeout' => $timeoutExtract,
			'connect_timeout' => $connectTimeout,
			'handler' => HandlerStack::create(new StreamHandler()),
		]);

		return $this->openAiStreamFallbackClient;
	}

	/**
	 * @param array<string, mixed> $requestOptions
	 */
	private function executeOpenAiRewritePostWithCurlFallback(array $requestOptions): \Psr\Http\Message\ResponseInterface
	{
		try {
			return $this->httpClientRewrite->post('https://api.openai.com/v1/chat/completions', $requestOptions);
		} catch (\Throwable $e) {
			if (!$this->isCurlSetoptArrayInvalidOptionsThrowable($e)) {
				throw $e;
			}
			Log::warning('OpenAI title rewrite: curl_setopt rejected; retrying via stream handler.');
			unset($requestOptions['curl']);

			return $this->openAiChatCompletionsStreamClient()->post('https://api.openai.com/v1/chat/completions', $requestOptions);
		}
	}

	/**
	 * Best-effort: reach the underlying PHP socket/resource behind Guzzle PSR-7 decorators so callers can stream_select().
	 */
	private function unwrapPhpStreamResourceFromBody(StreamInterface $body): mixed
	{
		$current = $body;
		for ($depth = 0; $depth < 16 && $current instanceof StreamInterface; $depth++) {
			if ($current instanceof GuzzleStream) {
				$rp = new \ReflectionProperty(GuzzleStream::class, 'stream');
				$rp->setAccessible(true);
				$r = $rp->getValue($current);

				return is_resource($r) ? $r : null;
			}
			$next = null;
			foreach (['remoteStream', 'stream'] as $prop) {
				try {
					$rp = new \ReflectionProperty($current, $prop);
					$rp->setAccessible(true);
					$candidate = $rp->getValue($current);
					if ($candidate instanceof StreamInterface) {
						$next = $candidate;
						break;
					}
				} catch (\ReflectionException) {
				}
			}
			if (!$next instanceof StreamInterface) {
				break;
			}
			$current = $next;
		}

		return null;
	}

	private function pulseOpenAiStreamHeartbeat(callable $pulseCb, float $waitStartedAt, float $tick, float &$lastPulse, string $urlForLog): void
	{
		$elapsedRounded = round($tick - $waitStartedAt, 2);
		try {
			($pulseCb)(max(0, (int) round($tick - $waitStartedAt)));
		} catch (\Throwable) {
		}
		Log::info('OpenAI extract heartbeat (stream wait)', ['url' => $urlForLog, 'elapsed_sec' => $elapsedRounded]);
		$lastPulse = $tick;
	}

	/**
	 * Read OpenAI chat.completion.chunk SSE lines and assemble assistant JSON text while firing progress callback.
	 * Used when scrape SSE needs pulses but Guzzle uses StreamHandler (no CURLOPT progress).
	 *
	 * When stream_select is unsupported, stream_set_timeout bounds each Guzzle fread so the loop can
	 * still reach heartbeat logic during long pre-token silence (see services.openai.sse_stream_read_timeout_sec).
	 *
	 * @param callable(int $elapsedSeconds):void $pulseCb
	 */
	private function drainOpenAiChatCompletionStream(ResponseInterface $response, callable $pulseCb, float $waitStartedAt, string $urlForLog): string
	{
		$pulseEvery = max(8, min(90, (int) config('services.openai.extract_heartbeat_sec', 22)));
		$lastPulse = microtime(true);
		$accum = '';
		$lineBuf = '';
		$done = false;
		$stream = $response->getBody();
		$phpResource = $this->unwrapPhpStreamResourceFromBody($stream);
		/** When stream_select fails (HTTPS/chunked wrappers), fread would block until tokens — bounded timeout yields idle loops for SSE heartbeats/logs. */
		$streamReadSliceSec = max(1, min(30, (int) config('services.openai.sse_stream_read_timeout_sec', 2)));
		if (is_resource($phpResource)) {
			@stream_set_timeout($phpResource, $streamReadSliceSec, 0);
		}
		// HTTP/1.1 chunked TLS streams often cannot be passed to stream_select() (filtered stream —
		// PHP 8 throws ValueError: "No stream arrays were passed" after stripping invalid fds).
		$selectEnabled = is_resource($phpResource);

		while (!$stream->eof()) {
			$tick = microtime(true);
			if (($tick - $lastPulse) >= $pulseEvery) {
				$this->pulseOpenAiStreamHeartbeat($pulseCb, $waitStartedAt, $tick, $lastPulse, $urlForLog);
			}

			$chunk = '';
			if ($selectEnabled && is_resource($phpResource)) {
				$read = [$phpResource];
				// PHP 8+: null write/except can contribute to ValueError — use empty arrays (by ref).
				$write = [];
				$except = [];
				$sel = 0;
				try {
					$sel = stream_select($read, $write, $except, 1, 0);
				} catch (\ValueError) {
					$selectEnabled = false;
				}

				if ($selectEnabled) {
					if ($sel === false) {
						$chunk = $stream->read(8192);
					} elseif ($sel > 0) {
						$chunk = $stream->read(65536);
					} else {
						continue;
					}
				}
			}
			if ($chunk === '') {
				$chunk = $stream->read(8192);
			}
			if ($chunk === '') {
				continue;
			}
			$lineBuf .= $chunk;
			while (($pos = strpos($lineBuf, "\n")) !== false) {
				$line = trim(substr($lineBuf, 0, $pos));
				$lineBuf = substr($lineBuf, $pos + 1);
				if ($line === '' || !str_starts_with($line, 'data:')) {
					continue;
				}
				$payload = trim(substr($line, 5));
				if ($payload === '[DONE]') {
					$done = true;
					break;
				}
				$evt = json_decode($payload, true);
				if (!is_array($evt)) {
					continue;
				}
				if (!empty($evt['error'])) {
					$err = $evt['error'];
					$msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE)) : (string) $err;

					throw new \RuntimeException('AI error: ' . $msg);
				}
				$delta = $evt['choices'][0]['delta'] ?? null;
				if (is_array($delta) && isset($delta['content']) && is_string($delta['content'])) {
					$accum .= $delta['content'];
				}
			}
			if ($done) {
				break;
			}
		}

		if (!$done && trim($lineBuf) !== '') {
			$line = trim($lineBuf);
			if (str_starts_with($line, 'data:')) {
				$payload = trim(substr($line, 5));
				if ($payload !== '' && $payload !== '[DONE]') {
					$evt = json_decode($payload, true);
					if (is_array($evt) && isset($evt['choices'][0]['delta']['content'])) {
						$d = $evt['choices'][0]['delta']['content'];
						if (is_string($d)) {
							$accum .= $d;
						}
					}
				}
			}
		}

		return $accum;
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
	 * Canonical email for Bid.EMAIL field; empty string means unknown after normalization.
	 */
	private function normalizeIssuingOrganization(string $raw): string
	{
		$s = trim(preg_replace('/\s+/u', ' ', $raw));
		if ($s === '' || strcasecmp($s, 'not provided') === 0 || strcasecmp($s, 'n/a') === 0) {
			return '';
		}

		return mb_substr($s, 0, 500);
	}

	/**
	 * Normalize optional ISSUING_ADDRESS / ISSUING_CONTACT_PHONE from model output for suffix blocks.
	 */
	private function normalizeIssuingContactSnippet(string $raw, bool $preserveNewlines, int $maxChars): string
	{
		$s = strip_tags(trim($raw));
		if ($preserveNewlines) {
			$s = str_replace(["\r\n", "\r"], "\n", $s);
			$s = preg_replace("/\n{3,}/u", "\n\n", $s) ?? $s;
			$parts = preg_split('/\n/u', $s) ?: [];
			$lines = [];
			foreach ($parts as $line) {
				$line = trim(preg_replace('/[ \t]+/u', ' ', (string) $line));
				if ($line !== '') {
					$lines[] = $line;
				}
			}
			$s = implode("\n", $lines);
		} else {
			$s = trim(preg_replace('/\s+/u', ' ', $s));
		}

		if ($s === '' || $this->isNotProvidedSnippet($s)) {
			return '';
		}

		return mb_strlen($s) > $maxChars ? mb_substr($s, 0, $maxChars) . '...' : $s;
	}

	private function isNotProvidedSnippet(string $s): bool
	{
		$t = str_replace(["\r\n", "\r"], "\n", $s);
		$t = strtolower(trim(explode("\n", $t)[0] ?? ''));

		return match (true) {
			$t === '',
			str_starts_with($t, 'not provided'),
			$t === 'n/a',
			$t === 'na',
			$t === 'unknown',
			$t === 'none',
			$t === 'tbd',
			$t === '—',
			$t === '-' => true,
			default => false,
		};
	}

	private function appendIssuingEntityContactSuffix(string $desc, string $address, string $phone): string
	{
		$address = trim(str_replace(["\r\n", "\r"], "\n", $address));
		$phone = trim($phone);
		if ($address === '' && $phone === '') {
			return $desc;
		}

		$lines = [];
		$lines[] = '---';
		$lines[] = '**Issuing entity — address & phone**';
		if ($address !== '') {
			$lines[] = 'Address: ' . str_replace(["\r\n", "\r"], "\n", $address);
		}
		if ($phone !== '') {
			$lines[] = 'Phone: ' . $phone;
		}

		$suffix = implode("\n", $lines);
		$base = str_replace(["\r\n", "\r"], "\n", rtrim((string) $desc));
		if ($base !== '' && str_ends_with($base, $suffix)) {
			return $desc;
		}

		return $base === '' ? $suffix : $base . "\n\n" . $suffix;
	}

	private function normalizeContactEmailFromExtract(mixed $raw): string
	{
		if (is_array($raw)) {
			$raw = implode(' ', $raw);
		}
		$s = trim((string) ($raw ?? ''));
		if ($s === '' || strcasecmp($s, 'not provided') === 0) {
			return '';
		}
		foreach (preg_split('/[\s,;|]+/', $s) as $part) {
			$part = trim($part);
			if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
				return mb_substr($part, 0, 255);
			}
		}

		return '';
	}

	/**
	 * @return 'government'|'private_company'|'uncertain'
	 */
	private function normalizePostingEntity(mixed $raw): string
	{
		if (is_array($raw)) {
			$raw = implode(' ', $raw);
		}
		$s = strtolower(preg_replace('/\s+/u', ' ', trim((string) ($raw ?? ''))));
		if ($s === '' || str_contains($s, 'not provided')) {
			return 'uncertain';
		}
		if ($s === 'private_company' || $s === 'government' || $s === 'uncertain') {
			return $s;
		}
		if (preg_match('/private[\s_-]*company|^corporate$/i', $s)) {
			return 'private_company';
		}
		if (preg_match('/^government$|^gov$|public sector|public agency|municipal|county /i', $s)) {
			return 'government';
		}
		if (preg_match('/uncertain|unknown/i', $s)) {
			return 'uncertain';
		}

		return 'uncertain';
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

	/**
	 * @param array<int, array<string, mixed>> $pages
	 */
	private function combinedBulkBidPageExcerptChars(array $pages): int
	{
		$n = 0;
		foreach ($pages as $p) {
			$n += mb_strlen((string) ($p['text_excerpt'] ?? ''));
			$n += mb_strlen((string) ($p['html_excerpt'] ?? ''));
			$n += mb_strlen((string) ($p['pdf_text_excerpt'] ?? ''));
		}
		return $n;
	}

	/**
	 * Proportionally trim bid_page excerpts until combined size is <= budget (bulk runs only).
	 *
	 * @param array<int, array<string, mixed>> $pages
	 */
	private function shrinkBulkBidPageExcerptsToBudget(array &$pages, int $budget): void
	{
		if ($pages === [] || $budget < 1) {
			return;
		}
		$keys = ['text_excerpt', 'html_excerpt', 'pdf_text_excerpt'];

		for ($pass = 0; $pass < 3; $pass++) {
			$total = $this->combinedBulkBidPageExcerptChars($pages);
			if ($total <= $budget) {
				return;
			}
			$ratio = $budget / max(1, $total);

			foreach ($pages as &$p) {
				foreach ($keys as $k) {
					$s = (string) ($p[$k] ?? '');
					if ($s === '') {
						continue;
					}
					$newLen = max(0, (int) floor(mb_strlen($s) * $ratio));
					$p[$k] = $newLen > 0 ? mb_substr($s, 0, min($newLen, mb_strlen($s))) : '';
				}
			}
			unset($p);
		}
	}

	/**
	 * @return array{pdf_text:int,listing_text:int,listing_html:int,bid_page_text:int,bid_page_html:int,bid_page_pdf:int}
	 */
	private function promptExcerptLimits(array $options): array
	{
		$pfx = !empty($options['bulk_mode']) ? 'ai_bulk_' : 'ai_standard_';

		return [
			'pdf_text' => (int) config('scraper.' . $pfx . 'pdf_text_chars'),
			'listing_text' => (int) config('scraper.' . $pfx . 'listing_text_chars'),
			'listing_html' => (int) config('scraper.' . $pfx . 'listing_html_chars'),
			'bid_page_text' => (int) config('scraper.' . $pfx . 'bid_page_text_chars'),
			'bid_page_html' => (int) config('scraper.' . $pfx . 'bid_page_html_chars'),
			'bid_page_pdf' => (int) config('scraper.' . $pfx . 'bid_page_pdf_text_chars'),
		];
	}

	/**
	 * Fire openai_heartbeat callback on a throttle while curl waits on OpenAI (keeps SSE from looking frozen).
	 * Uses CURLOPT_XFERINFOFUNCTION when available (CURLOPT_PROGRESSFUNCTION can fail curl_setopt_array on some PHP 8.4+/libcurl builds).
	 * Disabled by default: many PHP+FPM stacks reject xfer/progress callbacks inside Guzzle’s curl_setopt_array.
	 * Set OPENAI_EXTRACT_HEARTBEAT=true to try SSE pings (with automatic one-request retry without curl opts on failure).
	 *
	 * @param callable|null $heartbeatCb function (int $elapsedSeconds):void
	 */
	private function attachOpenAiExtractHeartbeatCurl(array &$requestOptions, mixed $heartbeatCb, float $waitStartedAt): void
	{
		if ($heartbeatCb === null || !is_callable($heartbeatCb) || !extension_loaded('curl')) {
			return;
		}
		if (!config('services.openai.extract_heartbeat', false)) {
			return;
		}

		$interval = (int) config('services.openai.extract_heartbeat_sec', 22);

		$lastFire = $waitStartedAt;

		$ping = function (...$_xferArgs) use ($heartbeatCb, $waitStartedAt, &$lastFire, $interval): int {
			$now = microtime(true);
			if (($now - $lastFire) < $interval) {
				return 0;
			}
			$lastFire = $now;
			try {
				($heartbeatCb)(max(0, (int) round($now - $waitStartedAt)));
			} catch (\Throwable) {
				/* ignore SSE / controller errors */

			}

			return 0;
		};

		$curlOpts = [
			\CURLOPT_NOPROGRESS => false,
		];
		if (\defined('CURLOPT_XFERINFOFUNCTION')) {
			$curlOpts[\CURLOPT_XFERINFOFUNCTION] = $ping;
		} elseif (\defined('CURLOPT_PROGRESSFUNCTION')) {
			$curlOpts[\CURLOPT_PROGRESSFUNCTION] = $ping;
		} else {
			return;
		}

		// array_merge reindexes integer keys — would destroy CURLOPT_* constants and break curl_setopt_array.
		$requestOptions['curl'] = array_replace($requestOptions['curl'] ?? [], $curlOpts);
	}

	private function isCurlSetoptArrayInvalidOptionsThrowable(\Throwable $e): bool
	{
		$chain = [$e];
		for ($prev = $e->getPrevious(); $prev instanceof \Throwable; $prev = $prev->getPrevious()) {
			$chain[] = $prev;
		}
		foreach ($chain as $t) {
			$m = strtolower($t->getMessage());
			if (str_contains($m, 'curl_setopt_array')) {
				return true;
			}
			if (str_contains($m, 'valid curl options')) {
				return true;
			}
			if ($t instanceof \ValueError && str_contains($m, 'curl')) {
				return true;
			}
		}

		return false;
	}
}
