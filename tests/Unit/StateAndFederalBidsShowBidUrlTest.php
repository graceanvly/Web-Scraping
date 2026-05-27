<?php

namespace Tests\Unit;

use App\Support\StateAndFederalBidsShowBidUrl;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StateAndFederalBidsShowBidUrlTest extends TestCase
{
	public function test_normalize_replaces_non_letters_and_digits_with_hyphens(): void
	{
		$this->assertSame(
			'LRSI-0425-250--Guardrail',
			StateAndFederalBidsShowBidUrl::normalizeUrlSearchTerm('LRSI-0425(250) Guardrail')
		);
	}

	public function test_normalize_keeps_unicode_letters(): void
	{
		if (! function_exists('mb_str_split')) {
			$this->markTestSkipped('mbstring required');
		}

		$this->assertSame('Café-123', StateAndFederalBidsShowBidUrl::normalizeUrlSearchTerm('Café 123'));
	}

	public function test_encode_slug_appends_primary_key_after_normalized_title_snippet(): void
	{
		$this->assertSame('Hello-World-4242', StateAndFederalBidsShowBidUrl::encodeBidSlug('Hello World', '4242'));
	}

	public function test_encode_slug_empty_title_uses_unknown_prefix(): void
	{
		$this->assertSame('Unknown-7', StateAndFederalBidsShowBidUrl::encodeBidSlug('', 7));
		$this->assertSame('Unknown-7', StateAndFederalBidsShowBidUrl::encodeBidSlug(null, 7));
	}

	public function test_encode_slug_null_id_returns_null(): void
	{
		$this->assertNull(StateAndFederalBidsShowBidUrl::encodeBidSlug('Hi', null));
	}

	public function test_show_bid_url_uses_config_base(): void
	{
		Config::set('scraper.stateandfederalbids_showbid_base_url', 'https://www.example.com/bids/ShowBid/');

		$this->assertSame(
			'https://www.example.com/bids/ShowBid/my-slug-1',
			StateAndFederalBidsShowBidUrl::showBidUrl('my-slug-1')
		);
	}

	public function test_url_for_bid_builds_full_url_when_trusting_local_pk(): void
	{
		Config::set('scraper.stateandfederalbids_showbid_base_url', 'https://www.example.com/bids/ShowBid/');
		Config::set('scraper.stateandfederalbids_showbid_trust_local_bid_id', true);

		$this->assertSame(
			'https://www.example.com/bids/ShowBid/A-5',
			StateAndFederalBidsShowBidUrl::urlForBid('A', 5)
		);
	}

	public function test_url_for_bid_null_without_trust_when_no_numeric_third_party(): void
	{
		Config::set('scraper.stateandfederalbids_showbid_base_url', 'https://www.example.com/bids/ShowBid/');
		Config::set('scraper.stateandfederalbids_showbid_trust_local_bid_id', false);

		$this->assertNull(StateAndFederalBidsShowBidUrl::urlForBid('A', 5));
		$this->assertNull(StateAndFederalBidsShowBidUrl::urlForBid('A', 5, ''));
		$this->assertNull(StateAndFederalBidsShowBidUrl::urlForBid('A', 5, 'not-numeric'));
	}

	public function test_url_for_bid_uses_numeric_third_party_without_trusting_local(): void
	{
		Config::set('scraper.stateandfederalbids_showbid_base_url', 'https://www.example.com/bids/ShowBid/');
		Config::set('scraper.stateandfederalbids_showbid_trust_local_bid_id', false);

		$this->assertSame(
			'https://www.example.com/bids/ShowBid/A-999',
			StateAndFederalBidsShowBidUrl::urlForBid('A', 5, '999')
		);
	}
}
