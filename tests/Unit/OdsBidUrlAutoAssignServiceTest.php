<?php

namespace Tests\Unit;

use App\Services\OdsBidUrlAutoAssignService;
use App\Services\OdsBidUrlListingService;
use Tests\TestCase;

class OdsBidUrlAutoAssignServiceTest extends TestCase
{
	public function test_latest_user_map_empty_for_no_ids(): void
	{
		$service = new OdsBidUrlAutoAssignService(new OdsBidUrlListingService());

		$this->assertSame([], $service->latestUserIdByBidUrlId([]));
	}

	public function test_assign_all_returns_zeros_when_tables_missing(): void
	{
		config([
			'scraper.ods_bidurl_table' => 'missing_bidurl_xyz',
			'scraper.ods_bidurl_history_table' => 'missing_history_xyz',
		]);

		$service = new OdsBidUrlAutoAssignService(new OdsBidUrlListingService());
		$stats = $service->assignAllUnassigned();

		$this->assertSame([
			'assigned' => 0,
			'skipped_no_history' => 0,
			'skipped_already' => 0,
			'failed' => 0,
		], $stats);
	}
}
