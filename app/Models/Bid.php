<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Bid extends Model
{
	use HasFactory, CaseInsensitiveAttributes;

	protected $table = "bid";
	public $timestamps = false;
	protected $primaryKey = 'id';
	protected $sequence = 'BID_SEQ';

	protected static function booted(): void
	{
		static::creating(function (Bid $bid) {
			if (empty($bid->id) && empty($bid->ID)) {
				$result = DB::select("SELECT BID_SEQ.NEXTVAL AS NEXT_ID FROM DUAL");
				$bid->ID = $result[0]->next_id ?? $result[0]->NEXT_ID;
			}
		});
	}
	protected $fillable = [
		'TITLE',
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
		'NSN',
	];

	protected $casts = [
		'CREATED' => 'datetime',
		'LAST_MODIFIED' => 'datetime',
	];

}
