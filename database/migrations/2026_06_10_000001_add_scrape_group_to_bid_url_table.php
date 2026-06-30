<?php

use App\Support\BidUrlScrapeGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		$table = (string) config('scraper.bid_url_table', 'bid_url');
		$column = BidUrlScrapeGroup::column();
		$default = BidUrlScrapeGroup::default();

		if (!Schema::hasTable($table)) {
			return;
		}

		if (!Schema::hasColumn($table, $column)) {
			Schema::table($table, function (Blueprint $blueprint) use ($column, $default) {
				$blueprint->string($column, 64)->default($default)->after('name');
			});
		}

		DB::table($table)
			->whereNull($column)
			->orWhere($column, '')
			->update([$column => $default]);
	}

	public function down(): void
	{
		$table = (string) config('scraper.bid_url_table', 'bid_url');
		$column = BidUrlScrapeGroup::column();

		if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
			Schema::table($table, function (Blueprint $blueprint) use ($column) {
				$blueprint->dropColumn($column);
			});
		}
	}
};
