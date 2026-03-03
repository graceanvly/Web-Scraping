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
        Schema::create('bid', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->string('TITLE', 255)->nullable();
            $table->text('DESCRIPTION')->nullable();
            $table->string('EMAIL', 255)->nullable();
            $table->string('URL', 500)->nullable();
            $table->dateTime('CREATED')->nullable();
            $table->dateTime('ENDDATE')->nullable();
            $table->bigInteger('CATEGORYID')->nullable();
            $table->bigInteger('ENTITYID')->nullable();
            $table->bigInteger('SUBSCRIPTIONTYPEID')->nullable();
            $table->bigInteger('USERID')->nullable();
            $table->string('THIRD_PARTY_IDENTIFIER', 255)->nullable();
            $table->string('SOLICIATIONNUMBER', 255)->nullable();
            $table->dateTime('FEDDATE')->nullable();
            $table->bigInteger('SETASIDECODEID')->nullable();
            $table->string('NAICSCODE', 255)->nullable();
            $table->bigInteger('BID_URL_ID')->nullable();
            $table->string('INLINEURL', 500)->nullable();
            $table->integer('NEEDS_REVIEW')->default(0);
            $table->bigInteger('SOURCE_ID')->nullable();
            $table->bigInteger('STATEID')->nullable();
            $table->dateTime('LAST_MODIFIED')->nullable();
            $table->bigInteger('CATEGORY_ALIAS_ID')->nullable();
            $table->bigInteger('COUNTRY_ID')->nullable();
            $table->integer('UNDERREVIEW')->default(0);
            $table->bigInteger('NAICSCODE_INT')->nullable();
            $table->string('NSN', 255)->nullable();
            $table->text('raw_html')->nullable();
            $table->text('extracted_json')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bid');
    }
};
