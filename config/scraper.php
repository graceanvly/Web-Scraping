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
];
