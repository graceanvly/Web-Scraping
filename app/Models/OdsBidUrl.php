<?php

namespace App\Models;

use App\Models\Concerns\CaseInsensitiveAttributes;
use App\Support\BidUrlScrapeMarker;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Read-only view of legacy Oracle ODS BIDURL (unassigned URL listing). */
class OdsBidUrl extends Model
{
	use CaseInsensitiveAttributes;

	protected $table = 'BIDURL';

	protected $primaryKey = 'ID';

	public function getTable(): string
	{
		return (string) config('scraper.ods_bidurl_table', $this->table);
	}

	public function getKeyName(): string
	{
		return (string) config('scraper.ods_bidurl_id_column', $this->primaryKey);
	}

	public $timestamps = false;

	protected static function booted(): void
	{
		foreach (['saving', 'creating', 'updating', 'deleting'] as $event) {
			static::registerModelEvent($event, function (): void {
				throw new \RuntimeException('OdsBidUrl is read-only.');
			});
		}
	}

	protected $casts = [
		'start_time' => 'datetime',
		'end_time' => 'datetime',
		'START_TIME' => 'datetime',
		'END_TIME' => 'datetime',
	];

	protected function lastScrapedAt(): Attribute
	{
		return Attribute::get(function ($value) {
			if ($value !== null && $value !== '') {
				return $this->asDateTime($value);
			}

			return BidUrlScrapeMarker::readFromAttributes($this->getAttributes());
		});
	}
}
