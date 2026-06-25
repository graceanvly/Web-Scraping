<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Result of comparing a BidIdentity against an existing live or pending row. */
final class BidDuplicateMatch
{
	public const TIER_A = 'A';
	public const TIER_B = 'B';
	public const TIER_C = 'C';

	public function __construct(
		public readonly string $tier,
		public readonly string $table,
		public readonly int|string $recordId,
		public readonly string $reason,
		public readonly ?Model $model = null,
	) {
	}

	public function shouldSkipSave(): bool
	{
		return $this->tier === self::TIER_A
			|| $this->tier === self::TIER_B
			|| $this->tier === self::TIER_C;
	}

	public function isPossibleDuplicate(): bool
	{
		return false;
	}
}
