<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
	use HasFactory;

	protected $table = "bid";
	public $timestamps = false;
	protected $primaryKey = 'id';
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

	/**
	 * Case-insensitive attribute access so both Oracle (lowercase)
	 * and MySQL (uppercase) column names resolve correctly.
	 */
	public function getAttribute($key)
	{
		$value = parent::getAttribute($key);
		if ($value === null && $key !== strtolower($key)) {
			$value = parent::getAttribute(strtolower($key));
		}
		if ($value === null && $key !== strtoupper($key)) {
			$value = parent::getAttribute(strtoupper($key));
		}
		return $value;
	}

	public function setAttribute($key, $value)
	{
		$lower = strtolower($key);
		if ($lower !== $key && array_key_exists($lower, $this->attributes)) {
			return parent::setAttribute($lower, $value);
		}
		return parent::setAttribute($key, $value);
	}
}
