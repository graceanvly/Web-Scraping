<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

class ScrapeLog extends Model
{
    use CaseInsensitiveAttributes;
    public $timestamps = false;
    protected $sequence = 'SCRAPE_LOGS_ID_SEQ';

    protected static function booted(): void
    {
        static::creating(function (ScrapeLog $log) {
            if (empty($log->id) && empty($log->ID)) {
                $result = \Illuminate\Support\Facades\DB::select("SELECT SCRAPE_LOGS_ID_SEQ.NEXTVAL AS NEXT_ID FROM DUAL");
                $log->ID = $result[0]->next_id ?? $result[0]->NEXT_ID;
            }
        });
    }

    protected $fillable = ['bid_url_id', 'url', 'level', 'message', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
