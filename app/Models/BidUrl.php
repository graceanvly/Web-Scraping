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
	];

	protected $casts = [
		'start_time' => 'datetime',
        'end_time' => 'datetime',
	];
}
