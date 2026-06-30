<?php

namespace Tests\Unit;

use App\Services\OdsBidUrlListingService;
use Tests\TestCase;

class OdsBidUrlListingServiceTest extends TestCase
{
	public function test_paginate_unassigned_returns_empty_when_table_missing(): void
	{
		config(['scraper.ods_bidurl_table' => 'definitely_missing_bidurl_table_xyz']);

		$service = new OdsBidUrlListingService();
		$page = $service->paginateUnassigned('', 50);

		$this->assertSame(0, $page->total());
		$this->assertCount(0, $page->items());
	}
}
