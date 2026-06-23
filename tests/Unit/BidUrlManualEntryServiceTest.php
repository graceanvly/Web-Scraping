<?php

namespace Tests\Unit;

use App\Services\BidUrlManualEntryService;
use ReflectionMethod;
use Tests\TestCase;

class BidUrlManualEntryServiceTest extends TestCase
{
	public function test_bid_url_row_to_select_option_reads_oracle_column_names(): void
	{
		config([
			'scraper.bid_url_id_column' => 'ID',
			'scraper.bid_url_url_column' => 'URL',
			'scraper.bid_url_name_column' => 'NAME',
		]);

		$row = (object) [
			'ID' => 1171,
			'URL' => 'https://example.gov/bids',
			'NAME' => 'Example County',
		];

		$service = new BidUrlManualEntryService();
		$method = new ReflectionMethod(BidUrlManualEntryService::class, 'bidUrlRowToSelectOption');
		$method->setAccessible(true);

		$opt = $method->invoke($service, $row);

		$this->assertSame([
			'id' => 1171,
			'label' => 'Example County — https://example.gov/bids',
			'url' => 'https://example.gov/bids',
		], $opt);
	}

	public function test_bid_url_row_to_select_option_falls_back_to_url_only_label(): void
	{
		$row = (object) [
			'id' => 42,
			'url' => 'https://example.gov/list',
			'name' => '',
		];

		$service = new BidUrlManualEntryService();
		$method = new ReflectionMethod(BidUrlManualEntryService::class, 'bidUrlRowToSelectOption');
		$method->setAccessible(true);

		$opt = $method->invoke($service, $row);

		$this->assertSame([
			'id' => 42,
			'label' => 'https://example.gov/list',
			'url' => 'https://example.gov/list',
		], $opt);
	}

	public function test_is_missing_history_table_error_detects_oracle_942(): void
	{
		$service = new BidUrlManualEntryService();
		$method = new ReflectionMethod(BidUrlManualEntryService::class, 'isMissingHistoryTableError');
		$method->setAccessible(true);

		$this->assertTrue($method->invoke($service, new \Exception('ORA-00942: table or view does not exist')));
		$this->assertFalse($method->invoke($service, new \Exception('ORA-00001: unique constraint violated')));
	}
}
