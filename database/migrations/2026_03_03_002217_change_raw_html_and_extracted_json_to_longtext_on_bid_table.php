<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE `bid` MODIFY `raw_html` LONGTEXT NULL');
        DB::statement('ALTER TABLE `bid` MODIFY `extracted_json` LONGTEXT NULL');
    }

    public function down()
    {
        DB::statement('ALTER TABLE `bid` MODIFY `raw_html` TEXT NULL');
        DB::statement('ALTER TABLE `bid` MODIFY `extracted_json` TEXT NULL');
    }
};
