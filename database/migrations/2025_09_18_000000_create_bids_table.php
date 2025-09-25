<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::create('bids', function (Blueprint $table) {
			$table->id();
			$table->string('url')->index();
			$table->string('title')->nullable();
			$table->dateTime('end_date')->nullable();
			$table->string('naics_code', 32)->nullable();
			$table->json('other_data')->nullable();
			$table->longText('raw_html')->nullable();
			$table->json('extracted_json')->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('bids');
	}
};
