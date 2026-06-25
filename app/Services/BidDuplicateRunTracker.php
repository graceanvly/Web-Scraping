<?php

namespace App\Services;

use App\Support\BidIdentity;

/** In-memory fingerprints for the current scrape job. */
final class BidDuplicateRunTracker
{
	/** @var array<string, true> */
	private array $seenTierA = [];

	/** @var array<string, true> */
	private array $seenTierBC = [];

	public function seen(BidIdentity $identity): bool
	{
		foreach ($identity->tierAFingerprintKeys() as $key) {
			if (isset($this->seenTierA[$key])) {
				return true;
			}
		}
		foreach ($identity->tierBCFingerprintKeys() as $key) {
			if (isset($this->seenTierBC[$key])) {
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
		foreach ($identity->tierBCFingerprintKeys() as $key) {
			$this->seenTierBC[$key] = true;
		}
	}
}
