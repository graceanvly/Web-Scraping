<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bid_url_id')->nullable();
            $table->string('url', 2048);
            $table->enum('level', ['success', 'warning', 'error'])->default('error');
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('scrape_logs');
    }
};
