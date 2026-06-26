<?php

namespace Tests\Unit;

use App\Support\BidUrlScrapeMarker;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BidUrlScrapeMarkerTest extends TestCase
{
	protected function tearDown(): void
	{
		BidUrlScrapeMarker::resetCache();
		parent::tearDown();
	}

	public function test_read_from_attributes_uses_end_time_when_configured(): void
	{
		config(['scraper.bid_url_last_scraped_column' => 'END_TIME']);
		BidUrlScrapeMarker::resetCache();

		$at = Carbon::parse('2026-06-10 14:30:00');
		$read = BidUrlScrapeMarker::readFromAttributes([
			'ID' => 5,
			'END_TIME' => $at,
		]);

		$this->assertNotNull($read);
		$this->assertSame('2026-06-10 14:30:00', $read->format('Y-m-d H:i:s'));
	}

	public function test_finish_scrape_update_attributes_omits_last_scraped_on_oracle_marker(): void
	{
		config(['scraper.bid_url_last_scraped_column' => 'END_TIME']);
		BidUrlScrapeMarker::resetCache();

		$start = Carbon::parse('2026-06-10 14:00:00');
		$end = Carbon::parse('2026-06-10 14:30:00');
		$attrs = BidUrlScrapeMarker::finishScrapeUpdateAttributes($start, $end);

		$this->assertArrayHasKey('end_time', $attrs);
		$this->assertArrayNotHasKey('last_scraped_at', $attrs);
	}

	public function test_restore_last_scraped_attributes_omits_last_scraped_on_oracle_marker(): void
	{
		config(['scraper.bid_url_last_scraped_column' => 'END_TIME']);
		BidUrlScrapeMarker::resetCache();

		$scraped = Carbon::parse('2026-06-10 14:30:00');
		$end = Carbon::parse('2026-06-10 15:00:00');
		$attrs = BidUrlScrapeMarker::restoreLastScrapedAttributes($scraped, $end);

		$this->assertSame([], $attrs);
	}

	public function test_restore_last_scraped_attributes_maps_to_end_time_when_missing(): void
	{
		config(['scraper.bid_url_last_scraped_column' => 'END_TIME']);
		BidUrlScrapeMarker::resetCache();

		$scraped = Carbon::parse('2026-06-10 14:30:00');
		$attrs = BidUrlScrapeMarker::restoreLastScrapedAttributes($scraped, null);

		$this->assertArrayNotHasKey('last_scraped_at', $attrs);
		$this->assertArrayHasKey('end_time', $attrs);
		$this->assertSame('2026-06-10 14:30:00', $attrs['end_time']->format('Y-m-d H:i:s'));
	}
}
