<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BidUrl extends Model
{
	use HasFactory;

    protected $table = "bid_url";

    public $timestamps = false;

	protected $fillable = [
		'url',
        'name',
        'start_time',
        'end_time',
        'weight',
        'user_id',
		'check_changes',
		'visit_required',
		'checksum',
		'valid',
		'third_party_url_id',
		'username',
		'password',
		'last_scraped_at',
	];

	protected $casts = [
		'start_time' => 'datetime',
        'end_time' => 'datetime',
		'last_scraped_at' => 'datetime',
	];

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
