<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use App\Support\BidUrlScrapeMarker;
use App\Support\BidUrlScrapeGroup;
use App\Support\BidUrlTableConfig;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BidUrl extends Model
{
	use HasFactory, CaseInsensitiveAttributes;

    protected $table = "bid_url";

    protected $primaryKey = 'id';

    public function getTable(): string
    {
        return BidUrlTableConfig::table();
    }

    public function getKeyName(): string
    {
        return (string) config('scraper.bid_url_id_column', $this->primaryKey);
    }

    public $timestamps = false;

    protected $sequence = 'BID_URL_SEQ';

    protected static function booted(): void
    {
        static::creating(function (BidUrl $model) {
            $key = $model->getKeyName();
            if (!empty($model->getAttribute($key)) || !empty($model->getAttribute(strtoupper($key)))) {
                return;
            }

            $sequence = BidUrlTableConfig::sequence();
            try {
                $result = DB::select("SELECT {$sequence}.NEXTVAL AS NEXT_ID FROM DUAL");
                $nextId = $result[0]->next_id ?? $result[0]->NEXT_ID ?? null;
                if ($nextId !== null) {
                    $model->setAttribute($key, $nextId);
                }
            } catch (\Throwable) {
                // MySQL / environments without Oracle sequence: use auto-increment id.
            }

            if (BidUrlScrapeGroup::hasColumn()) {
                $col = BidUrlScrapeGroup::column();
                $current = $model->getAttribute($col) ?? $model->getAttribute('scrape_group');
                if ($current === null || trim((string) $current) === '') {
                    $model->setAttribute($col, BidUrlScrapeGroup::default());
                }
            }
        });
    }

	protected $fillable = [
		'url',
        'name',
        'scrape_group',
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

	protected function lastScrapedAt(): Attribute
	{
		return Attribute::get(function ($value) {
			if ($value !== null && $value !== '') {
				return $this->asDateTime($value);
			}

			return BidUrlScrapeMarker::readFromModel($this);
		});
	}

}
