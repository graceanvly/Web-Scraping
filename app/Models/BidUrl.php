<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BidUrl extends Model
{
	use HasFactory, CaseInsensitiveAttributes;

    protected $table = "bid_url";

    public $timestamps = false;
    protected $sequence = 'BIDURL_SEQ';

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

}
