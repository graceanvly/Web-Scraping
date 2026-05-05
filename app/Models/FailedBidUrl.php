<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FailedBidUrl extends Model
{
    use HasFactory, CaseInsensitiveAttributes;

    protected $table = 'failed_bid_urls';

    public $timestamps = false;

    protected $fillable = [
        'original_bid_url_id',
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
        'failure_message',
        'failed_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'last_scraped_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
