<?php

namespace App\Support;

use App\Models\Bid;
use App\Models\TempBid;
use App\Services\BidReferenceLookupService;
use Illuminate\Support\Carbon;

/** JSON shape for bid detail modals (live + pending). */
final class BidDetailPayload
{
	public static function fromLive(Bid $bid, BidReferenceLookupService $lookup): array
	{
		return self::build(
			source: 'live',
			title: $bid->TITLE,
			description: $bid->DESCRIPTION,
			email: $bid->EMAIL,
			url: $bid->URL,
			endDate: $bid->ENDDATE,
			naics: $bid->NAICSCODE,
			created: $bid->CREATED ?? $bid->LAST_MODIFIED,
			entityId: $bid->ENTITYID,
			lookup: $lookup,
		);
	}

	public static function fromPending(TempBid $bid, BidReferenceLookupService $lookup): array
	{
		return self::build(
			source: 'pending',
			title: $bid->TITLE,
			description: $bid->DESCRIPTION,
			email: $bid->EMAIL,
			url: $bid->URL,
			endDate: $bid->ENDDATE,
			naics: $bid->NAICSCODE,
			created: $bid->created_at ?? $bid->CREATED,
			entityId: $bid->ENTITYID,
			lookup: $lookup,
		);
	}

	private static function build(
		string $source,
		mixed $title,
		mixed $description,
		mixed $email,
		mixed $url,
		mixed $endDate,
		mixed $naics,
		mixed $created,
		mixed $entityId,
		BidReferenceLookupService $lookup,
	): array {
		$eid = (int) ($entityId ?? 0);
		$entityLabel = null;
		if ($eid > 0) {
			$opt = $lookup->getEntityOptionById($eid);
			$entityLabel = $opt['label'] ?? ('Entity #' . $eid);
		}

		return [
			'source' => $source,
			'TITLE' => trim((string) ($title ?? '')) ?: 'Untitled',
			'DESCRIPTION' => (string) ($description ?? ''),
			'EMAIL' => trim((string) ($email ?? '')),
			'URL' => trim((string) ($url ?? '')),
			'ENDDATE' => self::isoDate($endDate),
			'NAICSCODE' => trim((string) ($naics ?? '')),
			'CREATED' => self::isoDate($created),
			'ENTITYID' => $eid > 0 ? $eid : null,
			'entity_label' => $entityLabel,
		];
	}

	private static function isoDate(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}
		try {
			return Carbon::parse($value)->toIso8601String();
		} catch (\Throwable) {
			return null;
		}
	}
}
