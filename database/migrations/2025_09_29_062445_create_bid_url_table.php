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
        Schema::create('bid_url', function (Blueprint $table) {
            $table->id(); // ID NUMBER(10,0) -> AUTO_INCREMENT PRIMARY KEY
            $table->string('url', 255)->nullable();
            $table->string('name', 128)->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->bigInteger('weight')->default(1);
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('check_changes')->default(0);
            $table->bigInteger('visit_required')->default(0);
            $table->decimal('checksum', 24, 0)->nullable();
            $table->integer('valid')->default(1);
            $table->bigInteger('third_party_url_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_url');
    }
};
