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
			ThirdPartyProcurementPortalUrl::resolveSavedBidUrl(
				'https://www.demandstar.com/app/agency/bids',
				'https://www.example.gov/bid/123'
			)
		);
	}

	public function test_saved_bid_url_falls_back_to_agency_listing_when_ai_detail_is_portal(): void
	{
		$listing = 'https://www.wpb.org/Departments/Procurement/Solicitations';

		$this->assertSame(
			$listing,
			ThirdPartyProcurementPortalUrl::resolveSavedBidUrl(
				$listing,
				'https://network.demandstar.com/for-business'
			)
		);
	}

	public function test_saved_bid_url_keeps_detail_when_unrestricted(): void
	{
		$detail = 'https://www.example.gov/bid/123';

		$this->assertSame(
			$detail,
			ThirdPartyProcurementPortalUrl::resolveSavedBidUrl('https://www.example.gov/bids', $detail)
		);
	}

	public function test_saved_bid_url_uses_matched_scraped_detail_page(): void
	{
		$listing = 'https://www.wpb.org/Departments/Procurement/Solicitations';
		$detail = 'https://www.wpb.org/Bids/ITB-25.26.115-SS-Belmonte-Rd-Pershing-Way-Utility-Improvements';
		$bidPages = [[
			'title' => 'Belmonte Rd & Pershing Way Utility Improvements, WM, San SW, LS #9',
			'url' => $detail,
		]];

		$this->assertSame(
			$detail,
			ThirdPartyProcurementPortalUrl::resolveSavedBidUrl(
				$listing,
				'https://network.demandstar.com/for-business',
				$bidPages,
				'Belmonte Rd & Pershing Way Utility Improvements, WM, San SW, LS #9'
			)
		);
	}
}
