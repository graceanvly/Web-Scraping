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
    protected $sequence = 'FAILED_BID_URLS_ID_SEQ';

    protected static function booted(): void
    {
        static::creating(function (FailedBidUrl $model) {
            if (empty($model->id) && empty($model->ID)) {
                $result = \Illuminate\Support\Facades\DB::select("SELECT FAILED_BID_URLS_ID_SEQ.NEXTVAL AS NEXT_ID FROM DUAL");
                $model->ID = $result[0]->next_id ?? $result[0]->NEXT_ID;
            }
        });
    }

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
