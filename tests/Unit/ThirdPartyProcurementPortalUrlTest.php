<?php

namespace Tests\Unit;

use App\Support\ThirdPartyProcurementPortalUrl;
use Tests\TestCase;

class ThirdPartyProcurementPortalUrlTest extends TestCase
{
	/** @dataProvider portalUrlProvider */
	public function test_references_portal(string $url): void
	{
		$this->assertTrue(ThirdPartyProcurementPortalUrl::referencesPortal($url));
	}

	public static function portalUrlProvider(): array
	{
		return [
			['https://www.demandstar.com/app/agency/123/bids'],
			['https://agency.vendorlink.com/solicitations/1'],
			['https://www.bidnetdirect.com/public/solicitation/abc'],
			['https://www.publicpurchase.com/gems/worth,tx/buyer/public/home'],
		];
	}

	public function test_does_not_reference_unrelated_portals(): void
	{
		$this->assertFalse(ThirdPartyProcurementPortalUrl::referencesPortal('https://www.example.gov/bids'));
	}

	public function test_saved_bid_url_is_empty_when_source_is_restricted(): void
	{
		$this->assertSame(
			'',
			ThirdPartyProcurementPortalUrl::savedBidUrl(
				'https://www.demandstar.com/app/agency/bids',
				'https://www.example.gov/bid/123'
			)
		);
	}

	public function test_saved_bid_url_is_empty_when_detail_is_restricted(): void
	{
		$this->assertSame(
			'',
			ThirdPartyProcurementPortalUrl::savedBidUrl(
				'https://www.example.gov/bids',
				'https://www.bidnetdirect.com/public/solicitation/abc'
			)
		);
	}

	public function test_saved_bid_url_keeps_detail_when_unrestricted(): void
	{
		$detail = 'https://www.example.gov/bid/123';

		$this->assertSame(
			$detail,
			ThirdPartyProcurementPortalUrl::savedBidUrl('https://www.example.gov/bids', $detail)
		);
	}
}
