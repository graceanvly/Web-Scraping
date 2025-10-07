<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
	use HasFactory;

	protected $table = "bid";
	public $timestamps = false;
	protected $primaryKey = 'ID';
	protected $fillable = [
		'TTITLE',
		'DESCRIPTION',
		'EMAIL',
		'URL',
		'CREATED',
		'ENDDATE',
		'CATEGORYID',
		'ENTITYID',
		'SUBSCRIPTIONTYPEID',
		'USERID',
		'THIRD_PARTY_IDENTIFIER',
		'SOLICIATIONNUMBER',
		'FEDDATE',
		'SETASIDECODEID',
		'NAICSCODE',
		'BID_URL_ID',
		'INLINEURL',
		'NEEDS_REVIEW',
		'SOURCE_ID',
		'STATEID',
		'LAST_MODIFIED',
		'CATEGORY_ALIAS_ID',
		'COUNTRY_ID',
		'UNDERREVIEW',
		'NAICSCODE_INT',
		'NSN'

	];

	protected $casts = [
		'CREATED' => 'datetime',
		'LAST_MODIFIED' => 'datetime',
	];
}
