<?php

namespace App\Services;

use App\Support\BidIdentity;

/** In-memory Tier-A fingerprints for the current scrape job. */
final class BidDuplicateRunTracker
{
	/** @var array<string, true> */
	private array $seenTierA = [];

	public function seen(BidIdentity $identity): bool
	{
		foreach ($identity->tierAFingerprintKeys() as $key) {
			if (isset($this->seenTierA[$key])) {
				return true;
			}
		}

		return false;
	}

	public function remember(BidIdentity $identity): void
	{
		foreach ($identity->tierAFingerprintKeys() as $key) {
			$this->seenTierA[$key] = true;
		}
	}
}
