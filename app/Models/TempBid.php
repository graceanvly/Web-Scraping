<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Scraped bid awaiting user approval. Lives in `bids_temp`; on approval the
 * business columns are copied into a real `App\Models\Bid` (which applies the
 * Oracle sequence + production defaults) and the temp row is deleted.
 *
 * Unlike Bid this uses a plain auto-increment id and real timestamps — it is
 * never written to the live `bid` table directly.
 */
class TempBid extends Model
{
	use CaseInsensitiveAttributes;

	protected $table = 'bids_temp';
	protected $primaryKey = 'id';
	public $timestamps = true;

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
		'source_listing_url',
		'bid_url_name',
	];

	protected $casts = [
		'CREATED' => 'datetime',
		'ENDDATE' => 'datetime',
		'LAST_MODIFIED' => 'datetime',
	];

	/** Business columns that map 1:1 onto the live `bid` table. */
	public const BID_COLUMNS = [
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

	/** Attributes for a new live Bid, keyed by the live column names. */
	public function toLiveBidAttributes(): array
	{
		$out = [];
		foreach (self::BID_COLUMNS as $col) {
			$out[$col] = $this->getAttribute($col);
		}

		return $out;
	}
}
