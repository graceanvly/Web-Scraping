<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_bid_urls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_bid_url_id')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('name', 255)->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->bigInteger('weight')->default(1);
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('check_changes')->default(0);
            $table->bigInteger('visit_required')->default(0);
            $table->decimal('checksum', 24, 0)->nullable();
            $table->integer('valid')->default(1);
            $table->bigInteger('third_party_url_id')->nullable();
            $table->string('username', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_bid_urls');
    }
};
