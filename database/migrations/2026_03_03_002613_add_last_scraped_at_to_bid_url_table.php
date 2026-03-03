<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('bid_url', function (Blueprint $table) {
            $table->timestamp('last_scraped_at')->nullable()->after('password');
        });
    }

    public function down()
    {
        Schema::table('bid_url', function (Blueprint $table) {
            $table->dropColumn('last_scraped_at');
        });
    }
};
