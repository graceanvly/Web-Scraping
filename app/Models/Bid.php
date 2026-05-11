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

			$defaults = [
				'CATEGORYID' => 0,
				'ENTITYID' => 0,
				'SUBSCRIPTIONTYPEID' => 0,
				'NEEDS_REVIEW' => 0,
				'INLINEURL' => 0,
				'SOURCE_ID' => 0,
				'STATEID' => 0,
				'USERID' => 0,
				'UNDERREVIEW' => 0,
			];

			if (is_null($bid->getAttribute('COUNTRY_ID'))) {
				$bid->setAttribute('COUNTRY_ID', 'us');
			}

			foreach ($defaults as $col => $default) {
				if (is_null($bid->getAttribute($col))) {
					$bid->setAttribute($col, $default);
				}
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
		'raw_html',
		'extracted_json',
	];

	protected $casts = [
		'CREATED' => 'datetime',
		'LAST_MODIFIED' => 'datetime',
	];

}
