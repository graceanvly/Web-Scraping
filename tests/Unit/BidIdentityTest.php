<?php

namespace Tests\Unit;

use App\Support\BidIdentity;
use Tests\TestCase;

class BidIdentityTest extends TestCase
{
	public function test_normalize_url_strips_scheme_www_and_trailing_slash(): void
	{
		$this->assertSame(
			'example.gov/bids/123',
			BidIdentity::normalizeUrlForMatch('https://www.Example.gov/bids/123/')
		);
	}

	public function test_normalize_title_strips_corporate_prefix_and_case(): void
	{
		$this->assertSame(
			'canal clearing',
			BidIdentity::normalizeTitle('Corporate: Canal Clearing')
		);
	}

	public function test_tier_b_matches_same_bid_url_end_date_and_raw_title(): void
	{
		$left = new BidIdentity(
			normalizedDetailUrl: 'example.gov/a',
			solicitationNumber: '',
			thirdPartyId: '',
			bidUrlId: 42,
			endDateYmd: '2026-06-10',
			titleNormalized: 'rewritten title',
			rawTitleNormalized: 'canal clearing',
		);
		$right = new BidIdentity(
			normalizedDetailUrl: 'example.gov/b',
			solicitationNumber: '',
			thirdPartyId: '',
			bidUrlId: 42,
			endDateYmd: '2026-06-10',
			titleNormalized: 'canal clearing',
			rawTitleNormalized: 'canal clearing',
		);

		$this->assertTrue($left->matchesTierB($right));
	}

	public function test_tier_c_matches_title_and_end_date_without_bid_url(): void
	{
		$left = new BidIdentity('a', '', '', 0, '2026-06-10', 'shared title', 'shared title');
		$right = new BidIdentity('b', '', '', 0, '2026-06-10', 'shared title', 'shared title');

		$this->assertTrue($left->matchesTierC($right));
	}

	public function test_tier_b_does_not_match_different_end_dates(): void
	{
		$left = new BidIdentity('a', '', '', 5, '2026-06-10', 'same title', 'same title');
		$right = new BidIdentity('a', '', '', 5, '2026-06-11', 'same title', 'same title');

		$this->assertFalse($left->matchesTierB($right));
	}

	public function test_from_scrape_extract_reads_solicitation_number(): void
	{
		$identity = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Test Bid', 'SOLICIATIONNUMBER' => 'ABC-123'],
			'https://example.gov/detail/1',
			10,
			'Test Bid'
		);

		$this->assertSame('abc-123', $identity->solicitationNumber);
		$this->assertTrue($identity->hasTierAKey());
	}

	public function test_url_lookup_variants_include_normalized_and_schemes(): void
	{
		$identity = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Test'],
			'https://www.Example.gov/bid/1/',
			null,
			'Test'
		);
		$variants = $identity->urlLookupVariants();

		$this->assertContains('https://www.Example.gov/bid/1/', $variants);
		$this->assertContains('example.gov/bid/1', $variants);
		$this->assertContains('https://example.gov/bid/1', $variants);
	}

	public function test_has_strong_url_for_tier_a_requires_path_beyond_domain(): void
	{
		$listing = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Portal'],
			'https://pr-webs-customer.des.wa.gov/',
			10,
		);
		$detail = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Bid'],
			'https://example.gov/bids/123',
			10,
		);

		$this->assertFalse($listing->hasStrongUrlForTierA());
		$this->assertFalse($listing->hasTierAKey());
		$this->assertTrue($detail->hasStrongUrlForTierA());
		$this->assertTrue($detail->hasTierAKey());
	}

	public function test_solicitation_number_is_tier_a_key_without_detail_path(): void
	{
		$identity = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Test', 'SOLICITATIONNUMBER' => 'SOL-1'],
			'https://pr-webs-customer.des.wa.gov/',
			10,
		);

		$this->assertFalse($identity->hasStrongUrlForTierA());
		$this->assertTrue($identity->hasTierAKey());
	}
}
