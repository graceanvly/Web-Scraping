<?php

namespace Tests\Unit;

use App\Support\BidUrlScrapeGroup;
use Tests\TestCase;

class BidUrlScrapeGroupTest extends TestCase
{
	public function test_default_group_is_test(): void
	{
		$this->assertSame('Test', BidUrlScrapeGroup::default());
	}

	public function test_apply_filter_is_noop_when_group_empty(): void
	{
		$query = \App\Models\BidUrl::query();
		$before = $query->toSql();

		BidUrlScrapeGroup::applyFilter($query, '');

		$this->assertSame($before, $query->toSql());
	}
}
