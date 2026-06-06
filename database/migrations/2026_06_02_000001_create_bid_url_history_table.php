<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('bid_url_history', function (Blueprint $table) {
			$table->id();
			$table->unsignedBigInteger('bid_url_id')->nullable();
			$table->timestamp('start_time')->nullable();
			$table->timestamp('end_time')->nullable();
			$table->unsignedBigInteger('user_id')->nullable();
			$table->index('bid_url_id');
			$table->index('start_time');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('bid_url_history');
	}
};
