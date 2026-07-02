<?php

namespace Tests\Unit;

use App\Services\OdsBidUrlSelectService;
use Tests\TestCase;

class OdsBidUrlSelectServiceTest extends TestCase
{
	public function test_search_returns_empty_when_ods_unavailable(): void
	{
		config(['scraper.ods_bidurl_table' => 'nonexistent_ods_bidurl_xyz']);

		$service = app(OdsBidUrlSelectService::class);

		$this->assertFalse($service->isAvailable());
		$this->assertSame([], $service->searchForSelect('test'));
		$this->assertNull($service->getOptionById(1));
	}
}
