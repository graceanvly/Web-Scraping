<?php

namespace Tests\Unit;

use App\Models\BidUrlHistory;
use Tests\TestCase;

class BidUrlHistoryModelTest extends TestCase
{
	public function test_defaults_to_mysql_history_table(): void
	{
		config([
			'scraper.bid_url_history_table' => 'bid_url_history',
			'scraper.bid_url_history_id_column' => 'id',
		]);

		$model = new BidUrlHistory();

		$this->assertSame('bid_url_history', $model->getTable());
		$this->assertSame('id', $model->getKeyName());
	}

	public function test_oracle_history_table_from_config(): void
	{
		config([
			'scraper.bid_url_history_table' => 'BID_URL_HISTORY',
			'scraper.bid_url_history_id_column' => 'ID',
		]);

		$model = new BidUrlHistory();

		$this->assertSame('BID_URL_HISTORY', $model->getTable());
		$this->assertSame('ID', $model->getKeyName());
	}

	public function test_explicit_history_table_override(): void
	{
		config([
			'scraper.bid_url_history_table' => 'CUSTOM_HISTORY',
			'scraper.bid_url_history_id_column' => 'HIST_ID',
		]);

		$model = new BidUrlHistory();

		$this->assertSame('CUSTOM_HISTORY', $model->getTable());
		$this->assertSame('HIST_ID', $model->getKeyName());
	}
}
