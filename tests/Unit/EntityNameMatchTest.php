<?php

namespace Tests\Unit;

use App\Support\EntityNameMatch;
use Tests\TestCase;

class EntityNameMatchTest extends TestCase
{
	public function test_canonical_key_normalizes_city_and_county_variants(): void
	{
		$this->assertSame('miami city', EntityNameMatch::canonicalKey('City of Miami'));
		$this->assertSame('miami city', EntityNameMatch::canonicalKey('Miami, City of'));
		$this->assertSame('denver city county', EntityNameMatch::canonicalKey('City and County of Denver'));
		$this->assertSame('routt county', EntityNameMatch::canonicalKey('Routt County'));
	}

	public function test_meaningful_url_hosts_skip_portal_vendors(): void
	{
		$hosts = EntityNameMatch::meaningfulUrlHosts(
			'https://www.bidnetdirect.com/colorado/cityofrifle',
			'https://www.co.routt.co.us/Bids.aspx'
		);

		$this->assertContains('co.routt.co.us', $hosts);
		$this->assertNotContains('bidnetdirect.com', $hosts);
	}

	public function test_strip_portal_vendor_names(): void
	{
		$this->assertSame('City of Rifle', EntityNameMatch::stripPortalVendorNames('BidNet Direct — City of Rifle'));
		$this->assertSame('', EntityNameMatch::stripPortalVendorNames('Bonfire Hub'));
	}

	public function test_organization_hint_from_subdomain(): void
	{
		$this->assertSame(
			'City Of Rifle',
			EntityNameMatch::organizationHintFromSubdomain('https://city-of-rifle.bonfirehub.com/portal/')
		);
	}
}
