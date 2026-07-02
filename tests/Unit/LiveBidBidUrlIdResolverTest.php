<?php

namespace Tests\Unit;

use App\Support\LiveBidBidUrlIdResolver;
use Tests\TestCase;

class LiveBidBidUrlIdResolverTest extends TestCase
{
	protected function tearDown(): void
	{
		LiveBidBidUrlIdResolver::resetCache();
		parent::tearDown();
	}

	public function test_normalize_url_trims_and_strips_trailing_slash(): void
	{
		$this->assertSame(
			'https://example.gov/bids',
			LiveBidBidUrlIdResolver::normalizeUrl('https://Example.gov/bids/')
		);
	}

	public function test_live_references_ods_by_default(): void
	{
		$this->assertTrue(LiveBidBidUrlIdResolver::liveReferencesOdsBidUrl());
	}

	public function test_resolve_for_live_write_returns_scraper_id_when_ods_mapping_disabled(): void
	{
		config(['scraper.live_bid_url_id_references_ods' => false]);

		$this->assertSame(1171, LiveBidBidUrlIdResolver::resolveForLiveWrite(1171, 'https://example.gov'));
	}
}
