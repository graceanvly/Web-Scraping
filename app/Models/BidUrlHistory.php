<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BidUrlHistory extends Model
{
	use CaseInsensitiveAttributes;

	protected $table = 'bid_url_history';

	public $timestamps = false;

	protected $primaryKey = 'id';

	protected $sequence = 'BID_URL_HISTORY_SEQ';

	public function getTable(): string
	{
		return (string) config('scraper.bid_url_history_table', $this->table);
	}

	public function getKeyName(): string
	{
		return (string) config('scraper.bid_url_history_id_column', $this->primaryKey);
	}

	protected static function booted(): void
	{
		static::creating(function (BidUrlHistory $model) {
			if (empty($model->id) && empty($model->ID)) {
				$sequence = (string) config('scraper.bid_url_history_sequence', 'BID_URL_HISTORY_SEQ');
				try {
					$result = DB::select("SELECT {$sequence}.NEXTVAL AS NEXT_ID FROM DUAL");
					$model->ID = $result[0]->next_id ?? $result[0]->NEXT_ID;
				} catch (\Throwable $e) {
					// MySQL / environments without Oracle sequence: use auto-increment id.
				}
			}
		});
	}

	protected $fillable = [
		'bid_url_id',
		'start_time',
		'end_time',
		'user_id',
	];

	protected $casts = [
		'start_time' => 'datetime',
		'end_time' => 'datetime',
	];
}
