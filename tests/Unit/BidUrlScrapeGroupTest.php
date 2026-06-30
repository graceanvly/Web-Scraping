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

	public function test_merge_group_names_includes_default_and_sorts(): void
	{
		$this->assertSame(['Production', 'Test', 'VE'], BidUrlScrapeGroup::mergeGroupNames(['VE', 'Production']));
	}

	public function test_merge_group_names_deduplicates_default(): void
	{
		$this->assertSame(['Test', 'VE'], BidUrlScrapeGroup::mergeGroupNames(['Test', 'VE', 'Test']));
	}
}
