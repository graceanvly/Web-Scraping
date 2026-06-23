<?php

namespace Tests\Unit;

use App\Support\BidDuplicateMatch;
use App\Support\BidIdentity;
use Tests\TestCase;

class BidDuplicateMatchTest extends TestCase
{
	public function test_tier_a_and_b_should_skip_save(): void
	{
		$a = new BidDuplicateMatch(BidDuplicateMatch::TIER_A, 'bid', 1, 'url');
		$b = new BidDuplicateMatch(BidDuplicateMatch::TIER_B, 'bids_temp', 2, 'title');

		$this->assertTrue($a->shouldSkipSave());
		$this->assertTrue($b->shouldSkipSave());
	}

	public function test_tier_c_is_possible_duplicate_only(): void
	{
		$c = new BidDuplicateMatch(BidDuplicateMatch::TIER_C, 'bids_temp', 3, 'title_enddate');

		$this->assertFalse($c->shouldSkipSave());
		$this->assertTrue($c->isPossibleDuplicate());
	}
}
