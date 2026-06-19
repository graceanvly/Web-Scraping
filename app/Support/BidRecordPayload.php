<?php

namespace App\Support;

use App\Models\Bid;
use DateTimeInterface;

/** Full bid row for JSON modals (all stored attributes). */
final class BidRecordPayload
{
	public static function fromLive(Bid $bid): array
	{
		$out = [];

		foreach ($bid->getAttributes() as $key => $value) {
			$out[self::normalizeKey((string) $key)] = self::serializeValue($value);
		}

		foreach (['raw_html', 'extracted_json'] as $extra) {
			$normalized = self::normalizeKey($extra);
			if (!array_key_exists($normalized, $out)) {
				$value = $bid->getAttribute($extra);
				if ($value !== null) {
					$out[$normalized] = self::serializeValue($value);
				}
			}
		}

		ksort($out);

		return $out;
	}

	private static function normalizeKey(string $key): string
	{
		return strtoupper($key);
	}

	private static function serializeValue(mixed $value): mixed
	{
		if ($value instanceof DateTimeInterface) {
			return $value->format('Y-m-d\TH:i:s');
		}

		return $value;
	}
}
