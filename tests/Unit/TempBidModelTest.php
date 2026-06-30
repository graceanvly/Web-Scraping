<?php

namespace Tests\Unit;

use App\Models\TempBid;
use Tests\TestCase;

class TempBidModelTest extends TestCase
{
	public function test_listing_order_sorts_oldest_first(): void
	{
		$sql = TempBid::query()->listingOrder()->toSql();

		$this->assertStringContainsString('order by', strtolower($sql));
		$this->assertStringContainsString('`id` asc', strtolower($sql));
		$this->assertStringContainsString('`created_at` asc', strtolower($sql));
	}
}
