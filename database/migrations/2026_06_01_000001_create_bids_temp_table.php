<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging table for scraped bids awaiting user approval. Mirrors the live `bid`
 * business columns so an approved row can be copied 1:1 into `bid`, plus a few
 * lowercase metadata columns for the review queue.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('bids_temp', function (Blueprint $table) {
            $table->bigIncrements('id');

            // --- mirror of `bid` business columns ---
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

            // --- review-queue metadata ---
            $table->string('source_listing_url', 500)->nullable();
            $table->string('bid_url_name', 255)->nullable();
            $table->timestamps();

            $table->index('BID_URL_ID');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bids_temp');
    }
};
