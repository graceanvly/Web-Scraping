<?php

namespace Tests\Unit;

use App\Services\BidDuplicateRunTracker;
use App\Support\BidIdentity;
use Tests\TestCase;

class BidDuplicateRunTrackerTest extends TestCase
{
	public function test_remembers_and_detects_tier_a_fingerprints_in_run(): void
	{
		$tracker = new BidDuplicateRunTracker();
		$identity = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Canal Clearing', 'SOLICIATIONNUMBER' => 'SOL-9'],
			'https://example.gov/bid/9',
			5,
			'Canal Clearing'
		);

		$this->assertFalse($tracker->seen($identity));
		$tracker->remember($identity);
		$this->assertTrue($tracker->seen($identity));
	}
}
