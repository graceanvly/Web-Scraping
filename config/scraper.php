<?php

	return [

	/*
	|--------------------------------------------------------------------------
	| Bid index (Oracle-safe listing)
	|--------------------------------------------------------------------------
	| Scraped bid_url rows can imply huge IN (...) lists — use EXISTS joins in code instead.
	| Limit by CREATED to avoid scanning millions of bid rows unless ?historical=1.
	*/
	'bid_listing_recent_days' => max(0, min(3660, (int) env('SCRAPER_BID_LISTING_RECENT_DAYS', 180))), // 0 = no date window

	/*
	|--------------------------------------------------------------------------
	| State & Federal Bids — ShowBid deep link (scraped row → production site)
	|--------------------------------------------------------------------------
	| Trailing slash optional; slug is appended after ShowBid/. See App\Support\StateAndFederalBidsShowBidUrl.
	*/
	'stateandfederalbids_showbid_base_url' => rtrim((string) env(
		'SCRAPER_STATEANDFEDERALBIDS_SHOWBID_BASE_URL',
		'https://www.stateandfederalbids.com/bids/ShowBid/'
	), '/') . '/',

	/**
	 * ShowBid slug suffix must be stateandfederalbids.com’s ODS bid PK (decoded from URL).
	 * Scraped bids get a local BID_SEQ id that usually does NOT match production — links error.
	 * When false (default): only build ShowBid URLs if THIRD_PARTY_IDENTIFIER is a numeric ODS id.
	 * When true: use scraper Bid.ID like legacy behavior (same Oracle/write DB as SAFB prod only).
	 */
	'stateandfederalbids_showbid_trust_local_bid_id' => filter_var(
		env('SCRAPER_STATEANDFEDERALBIDS_SHOWBID_TRUST_LOCAL_BID_ID', false),
		FILTER_VALIDATE_BOOL
	),

	/*
	|--------------------------------------------------------------------------
	| Directory users (bid assignee dropdown)
	|--------------------------------------------------------------------------
	| Oracle/production often uses a legacy user table, not Laravel's `users`.
	*/
	'directory_user_table' => env('SCRAPER_DIRECTORY_USER_TABLE', 'users'),
	'directory_user_pk' => env('SCRAPER_DIRECTORY_USER_PK', 'id'),
	'directory_user_time_zone_column' => env('SCRAPER_DIRECTORY_USER_TIME_ZONE_COLUMN', 'TIME_ZONE'),
	'directory_user_time_zone_value' => env('SCRAPER_DIRECTORY_USER_TIME_ZONE_VALUE', 'Asia/Manila'),

	/*
	|--------------------------------------------------------------------------
	| CATEGORY / STATE reference tables (IDs stored on bid)
	|--------------------------------------------------------------------------
	*/
	'category_table' => env('SCRAPER_CATEGORY_TABLE', 'category'),
	'category_id_column' => env('SCRAPER_CATEGORY_ID_COLUMN', 'id'),
	'category_name_column' => env('SCRAPER_CATEGORY_NAME_COLUMN', 'name'),

	'state_table' => env('SCRAPER_STATE_TABLE', 'state'),
	'state_id_column' => env('SCRAPER_STATE_ID_COLUMN', 'id'),
	'state_name_column' => env('SCRAPER_STATE_NAME_COLUMN', 'name'),
	'state_abbr_column' => env('SCRAPER_STATE_ABBR_COLUMN', 'abbreviation'),

	/*
	|--------------------------------------------------------------------------
	| BIDURL reference (BID_URL_ID on bid → BIDURL.ID)
	|--------------------------------------------------------------------------
	| Oracle ODS uses table BIDURL with columns ID, URL, NAME, USER_ID, etc.
	| Local MySQL dev uses bid_url with lowercase columns.
	*/
	'bid_url_table' => env('SCRAPER_BID_URL_TABLE', 'bid_url'),
	'bid_url_id_column' => env('SCRAPER_BID_URL_ID_COLUMN', 'id'),
	'bid_url_url_column' => env('SCRAPER_BID_URL_URL_COLUMN', 'url'),
	'bid_url_name_column' => env('SCRAPER_BID_URL_NAME_COLUMN', 'name'),
	'bid_url_user_id_column' => env('SCRAPER_BID_URL_USER_ID_COLUMN', 'user_id'),
	/** Empty = auto-detect last_scraped_at then END_TIME. Oracle BIDURL uses END_TIME. */
	'bid_url_last_scraped_column' => env('SCRAPER_BID_URL_LAST_SCRAPED_COLUMN', ''),

	/*
	|--------------------------------------------------------------------------
	| BIDURLHISTORY (scrape / manual visit log)
	|--------------------------------------------------------------------------
	| Oracle ODS: BIDURLHISTORY (ID, BID_URL_ID, START_TIME, END_TIME, USER_ID).
	| MySQL dev: bid_url_history. When SCRAPER_BID_URL_TABLE=BIDURL and history table
	| is unset, defaults to BIDURLHISTORY automatically.
	*/
	'bid_url_history_table' => env(
		'SCRAPER_BID_URL_HISTORY_TABLE',
		env('SCRAPER_BID_URL_TABLE', 'bid_url') === 'BIDURL' ? 'BIDURLHISTORY' : 'bid_url_history'
	),
	'bid_url_history_id_column' => env(
		'SCRAPER_BID_URL_HISTORY_ID_COLUMN',
		env('SCRAPER_BID_URL_TABLE', 'bid_url') === 'BIDURL' ? 'ID' : 'id'
	),
	'bid_url_history_sequence' => env('SCRAPER_BID_URL_HISTORY_SEQUENCE', 'BIDURLHISTORY_SEQ'),

	/** When false, allow saving bids without ENDDATE if URL/solicitation/third-party id is present. */
	'bid_require_enddate_for_save' => filter_var(env('SCRAPER_REQUIRE_ENDDATE_FOR_SAVE', true), FILTER_VALIDATE_BOOL),

	/** When true, skip entire Bid URL rows already scraped today (legacy). Default false — per-bid dedup is safer. */
	'bid_skip_url_if_scraped_today' => filter_var(env('SCRAPER_BID_SKIP_URL_IF_SCRAPED_TODAY', false), FILTER_VALIDATE_BOOL),

	/**
	 * When true, scrape failures (blocked site, unreadable page, exceptions) move the row from BIDURL
	 * into failed_bid_urls. Default false — keeps user assignments on BIDURL; failures go to scrape logs only.
	 */
	'bid_url_quarantine_on_scrape_error' => filter_var(env('SCRAPER_QUARANTINE_URL_ON_SCRAPE_ERROR', false), FILTER_VALIDATE_BOOL),

	/*
	|--------------------------------------------------------------------------
	| ENTITY reference (ENTITYID on bid)
	|--------------------------------------------------------------------------
	| Rows are matched in order: Bid URL name, email (exact), website/email domain vs non-portal URL hosts
	| (listing + detail), then fuzzy name using ISSUING_ORGANIZATION (AI) plus structured text patterns.
	| Point entity_table / columns at your Oracle (or MySQL) master entity list.
	*/
	'entity_resolve_enabled' => filter_var(env('SCRAPER_ENTITY_RESOLVE_ENABLED', true), FILTER_VALIDATE_BOOL),
	'entity_table' => env('SCRAPER_ENTITY_TABLE', 'entity'),
	'entity_id_column' => env('SCRAPER_ENTITY_ID_COLUMN', 'id'),
	'entity_email_columns' => array_values(array_filter(array_map(
		'trim',
		explode(',', (string) env('SCRAPER_ENTITY_EMAIL_COLUMNS', 'email')),
	))),
	'entity_name_columns' => array_values(array_filter(array_map(
		'trim',
		explode(',', (string) env('SCRAPER_ENTITY_NAME_COLUMNS', 'name')),
	))),
	'entity_website_columns' => array_values(array_filter(array_map(
		'trim',
		explode(',', (string) env('SCRAPER_ENTITY_WEBSITE_COLUMNS', '')),
	))),

	/*
	|--------------------------------------------------------------------------
	| Bulk scrape pacing (streaming / scrape-all jobs)
	|--------------------------------------------------------------------------
	*/
	/** Hard cap seconds per Bid URL row (fetch + AI + saves) before skipping remainder of rewrite / continuing. */
	'scrape_url_max_seconds' => max(120, min(7200, (int) env('SCRAPER_URL_MAX_SECONDS', 480))),
	/** Reserve seconds so we skip the second OpenAI rewrite call when nearing the URL cap (avoids long tail). */
	'scrape_title_rewrite_reserve_seconds' => max(30, min(900, (int) env('SCRAPER_TITLE_REWRITE_RESERVE_SECONDS', 90))),
	/** Skip batched rewrite when more than this many titles (would be one oversized API call). */
	'scrape_rewrite_max_titles' => max(10, min(500, (int) env('SCRAPER_REWRITE_MAX_TITLES', 120))),
	/** Rewrite titles in chunks of this size so bulk SSE can refresh between OpenAI batches. */
	'title_rewrite_chunk_titles' => max(4, min(35, (int) env('SCRAPER_TITLE_REWRITE_CHUNK', 10))),
	/*
	 * Batch listing Puppeteer: headless fires when extracted text &lt; 500 chars (“thin”).
	 * The Node subprocess blocks the SSE stream with little logging; cap subprocess time here.
	 */
	'batch_thin_listing_headless_max_sec' => max(15, min(180, (int) env('SCRAPER_BATCH_THIN_HEADLESS_MAX_SEC', 42))),
	/**
	 * In batch mode, skip Puppeteer when headless would run only because of thin listing text
	 * (HTTP succeeded; not SPA/browser-check/403)—faster batches; JS-only listings may be missed.
	 */
	'batch_skip_thin_headless' => filter_var(env('SCRAPER_BATCH_SKIP_THIN_HEADLESS', false), FILTER_VALIDATE_BOOL),
	/** In batch mode, skip Puppeteer when HTTP listing text is at least this long (SPA/heavy portals often still return usable HTML). */
	'batch_skip_headless_min_listing_chars' => max(500, min(50000, (int) env('SCRAPER_BATCH_SKIP_HEADLESS_MIN_LISTING_CHARS', 800))),
	/** Granicus municipal listings (e.g. wpb.org): extra Puppeteer settle time so /Bids/ detail links render. */
	'granicus_settle_ms' => max(2000, min(45000, (int) env('SCRAPER_GRANICUS_SETTLE_MS', 12000))),
	'strict_budget_granicus_settle_ms' => max(1000, min(30000, (int) env('SCRAPER_STRICT_BUDGET_GRANICUS_SETTLE_MS', 8000))),
	/** Max detail pages to follow on Granicus /Bids/ listings (batch cap is raised to this when higher). */
	'granicus_max_detail_pages' => max(1, min(20, (int) env('SCRAPER_GRANICUS_MAX_DETAIL_PAGES', 8))),
	/** When scrape-stream uses strict url_max_seconds (5 min checkbox), reserve this for AI + title rewrite after fetch. */
	'strict_budget_ai_reserve_seconds' => max(60, min(600, (int) env('SCRAPER_STRICT_BUDGET_AI_RESERVE_SECONDS', 120))),
	/** Max Puppeteer subprocess seconds for Bonfire/heavy portals under strict per-URL budget. */
	'strict_budget_heavy_puppeteer_max_sec' => max(30, min(240, (int) env('SCRAPER_STRICT_BUDGET_HEAVY_PUPPETEER_MAX_SEC', 90))),
	/** Heavy-portal settle delay (ms) cap under strict per-URL budget. */
	'strict_budget_heavy_settle_ms' => max(1000, min(30000, (int) env('SCRAPER_STRICT_BUDGET_HEAVY_SETTLE_MS', 8000))),

	/*
	|--------------------------------------------------------------------------
	| AI extract prompt excerpts (characters, mb_substr)
	|--------------------------------------------------------------------------
	| Bulk/scrape-stream uses tighter caps so long runs finish sooner and SSE does not appear "stuck".
	*/
	'ai_standard_pdf_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_STANDARD_PDF_TEXT_CHARS', 24000))),
	'ai_standard_listing_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_STANDARD_LISTING_TEXT_CHARS', 32000))),
	'ai_standard_listing_html_chars' => max(2000, min(48000, (int) env('SCRAPER_AI_STANDARD_LISTING_HTML_CHARS', 12000))),
	'ai_standard_bid_page_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_STANDARD_BID_PAGE_TEXT_CHARS', 28000))),
	'ai_standard_bid_page_html_chars' => max(1000, min(32000, (int) env('SCRAPER_AI_STANDARD_BID_PAGE_HTML_CHARS', 8000))),
	'ai_standard_bid_page_pdf_text_chars' => max(2000, min(48000, (int) env('SCRAPER_AI_STANDARD_BID_PAGE_PDF_TEXT_CHARS', 12000))),

	'ai_bulk_pdf_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_BULK_PDF_TEXT_CHARS', 14000))),
	'ai_bulk_listing_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_BULK_LISTING_TEXT_CHARS', 20000))),
	'ai_bulk_listing_html_chars' => max(2000, min(48000, (int) env('SCRAPER_AI_BULK_LISTING_HTML_CHARS', 10000))),
	'ai_bulk_bid_page_text_chars' => max(4000, min(96000, (int) env('SCRAPER_AI_BULK_BID_PAGE_TEXT_CHARS', 16000))),
	'ai_bulk_bid_page_html_chars' => max(1000, min(32000, (int) env('SCRAPER_AI_BULK_BID_PAGE_HTML_CHARS', 6000))),
	'ai_bulk_bid_page_pdf_text_chars' => max(2000, min(48000, (int) env('SCRAPER_AI_BULK_BID_PAGE_PDF_TEXT_CHARS', 9000))),
	/** After per-field truncation, proportional shrink if combined bid-page excerpts still exceed this (bulk_mode only). */
	'ai_bulk_bid_pages_total_budget_chars' => max(6000, min(200000, (int) env('SCRAPER_AI_BULK_PAGES_TOTAL_CHARS', 34000))),
	/**
	 * Max wall-clock seconds for a single bulk AI extract call. Bounds a slow/stalled OpenAI stream so one URL
	 * cannot consume nearly the whole per-URL budget; the URL is abandoned and the run continues. Still capped by
	 * the remaining per-URL budget.
	 */
	'ai_bulk_extract_max_seconds' => max(30, min(600, (int) env('SCRAPER_AI_BULK_EXTRACT_MAX_SECONDS', 150))),
	/** Reserve (seconds) kept free for saving bids after title rewrite; rewrite batches stop once remaining budget drops below this. */
	'scrape_save_reserve_seconds' => max(10, min(120, (int) env('SCRAPER_SAVE_RESERVE_SECONDS', 25))),

	/*
	|--------------------------------------------------------------------------
	| PDF download / parse (listing & detail URLs in ScraperService)
	|--------------------------------------------------------------------------
	*/
	'pdf_timeout' => max(15, min(300, (int) env('SCRAPER_PDF_TIMEOUT', 60))),
	/** Cap Smalot + pdftotext fallback wall time per PDF (misbehaving binaries can hang PHP indefinitely). */
	'pdf_parse_timeout_sec' => max(10, min(300, (int) env('SCRAPER_PDF_PARSE_TIMEOUT', 45))),
	/** Larger PDFs skip Smalot parse (often pathologically slow/hang); timed pdftotext only. */
	'pdf_smalot_max_bytes' => max(32768, min(5242880, (int) env('SCRAPER_PDF_SMALOT_MAX_BYTES', 524288))),

	/*
	|--------------------------------------------------------------------------
	| Puppeteer / Chrome (passed to Node; must use config() — not env() in services — when config:cached)
	|--------------------------------------------------------------------------
	*/
	'puppeteer_cache_dir' => trim((string) env('PUPPETEER_CACHE_DIR', '')),
	'chrome_single_process' => filter_var(env('SCRAPER_CHROME_SINGLE_PROCESS', false), FILTER_VALIDATE_BOOLEAN),
	/** Writable base dir for ephemeral profiles (e.g. /tmp/chrome-scraper on EC2). */
	'chrome_user_data_dir' => trim((string) env('SCRAPER_CHROME_USER_DATA_DIR', '')),
	/** Empty = puppeteer-launch default; try "shell" if Chrome fails on Linux. */
	'chrome_headless' => trim((string) env('SCRAPER_CHROME_HEADLESS', '')),
	'chrome_pipe' => filter_var(env('SCRAPER_CHROME_PIPE', false), FILTER_VALIDATE_BOOLEAN),
	'puppeteer_executable_path' => trim((string) env('PUPPETEER_EXECUTABLE_PATH', '')),
	'chrome_executable' => trim((string) env('SCRAPER_CHROME_EXECUTABLE', '')),
	'scraper_cookie' => trim((string) env('SCRAPER_COOKIE', '')),
	/**
	 * SSE “still running” text while Puppeteer runs (heavy Bonfire / JS portals often need 60–90s idle).
	 */
	'puppeteer_sse_pulse_sec' => max(8, min(90, (int) env('SCRAPER_PUPPETEER_SSE_PULSE_SEC', 15))),
];
