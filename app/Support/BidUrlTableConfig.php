<?php

namespace App\Support;

/** Resolves scraper-managed bid URL table/sequence; blocks legacy ODS BIDURL. */
final class BidUrlTableConfig
{
	public static function table(): string
	{
		$table = trim((string) config('scraper.bid_url_table', 'bid_url'));
		if ($table === '') {
			$table = 'bid_url';
		}

		if (strcasecmp($table, 'BIDURL') === 0) {
			throw new \RuntimeException(
				'SCRAPER_BID_URL_TABLE must not be the legacy ODS table BIDURL. '
				. 'Use the scraper-managed BID_URL table (SCRAPER_BID_URL_TABLE=BID_URL), then run php artisan config:clear.'
			);
		}

		return $table;
	}

	public static function sequence(): string
	{
		return (string) config('scraper.bid_url_sequence', 'BID_URL_SEQ');
	}
}
