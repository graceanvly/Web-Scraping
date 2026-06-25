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

	public function test_remembers_and_detects_tier_bc_fingerprints_in_run(): void
	{
		$tracker = new BidDuplicateRunTracker();
		$identity = BidIdentity::fromScrapeExtract(
			['TITLE' => 'Main Street Water Main', 'ENDDATE' => '2026-06-24', 'NAICSCODE' => '237110'],
			'https://example.gov/bid/1',
			42,
			'Main Street Water Main',
			'237110'
		);

		$this->assertFalse($tracker->seen($identity));
		$tracker->remember($identity);
		$this->assertTrue($tracker->seen($identity));
	}
}
