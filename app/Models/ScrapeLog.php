<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

class ScrapeLog extends Model
{
    use CaseInsensitiveAttributes;
    public $timestamps = false;

    protected $fillable = ['bid_url_id', 'url', 'level', 'message', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
