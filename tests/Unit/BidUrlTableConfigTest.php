<?php

namespace Tests\Unit;

use App\Support\BidUrlTableConfig;
use Tests\TestCase;

class BidUrlTableConfigTest extends TestCase
{
	public function test_rejects_legacy_bidurl_table(): void
	{
		config(['scraper.bid_url_table' => 'BIDURL']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('BID_URL');

		BidUrlTableConfig::table();
	}

	public function test_allows_bid_url_table(): void
	{
		config(['scraper.bid_url_table' => 'BID_URL']);

		$this->assertSame('BID_URL', BidUrlTableConfig::table());
	}
}
