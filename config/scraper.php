<?php

return [

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
	| ENTITY reference (ENTITYID on bid)
	|--------------------------------------------------------------------------
	| Rows are matched in order: email (exact), website/email domain vs bid URL host,
	| then fuzzy name using ISSUING_ORGANIZATION (AI) plus light patterns from text.
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
];
