<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BidReferenceLookupService
{
	private function cfg(string $key, mixed $default = null): mixed
	{
		return config("scraper.{$key}", $default);
	}

	private function rowAttr(object $row, string $column): mixed
	{
		foreach ((array) $row as $key => $value) {
			if (strcasecmp((string) $key, $column) === 0) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array{id: int|string, label: string}>
	 */
	public function getManilaAssignableUsersForSelect(): array
	{
		$table = (string) $this->cfg('directory_user_table', 'users');
		$pk = (string) $this->cfg('directory_user_pk', 'id');
		$tzCol = (string) $this->cfg('directory_user_time_zone_column', 'TIME_ZONE');
		$tzVal = (string) $this->cfg('directory_user_time_zone_value', 'Asia/Manila');

		try {
			$rows = DB::table($table)
				->where($tzCol, $tzVal)
				->orderBy($pk)
				->get();
		} catch (\Throwable $e) {
			Log::warning('BidReferenceLookup: could not load Manila users', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$id = $this->rowAttr($row, $pk);
			if ($id === null || $id === '') {
				continue;
			}
			$attrs = (array) $row;
			$upper = array_change_key_case($attrs, CASE_UPPER);
			$labelParts = array_filter([
				$upper['FIRSTNAME'] ?? $upper['FIRST_NAME'] ?? null,
				$upper['LASTNAME'] ?? $upper['LAST_NAME'] ?? null,
				$upper['EMAIL'] ?? null,
				$upper['NAME'] ?? null,
			], fn ($v) => $v !== null && trim((string) $v) !== '');
			$label = trim(implode(' ', array_map('trim', $labelParts)));
			if ($label === '') {
				$label = (string) $id;
			} else {
				$label = $id . ' – ' . $label;
			}
			$out[] = ['id' => $id, 'label' => $label];
		}

		return $out;
	}

	public function resolveCategoryId(?string $bidCategoryHint, string $title, string $description): ?int
	{
		$hint = $this->normalizeHint($bidCategoryHint);
		if ($hint === '') {
			$hint = $this->normalizeHint($this->categoryHintFromText($title . ' ' . $description));
		}
		if ($hint === '') {
			return null;
		}

		$rows = $this->cachedCategories();
		if ($rows->isEmpty()) {
			return null;
		}

		return $this->bestCategoryId($hint, $rows);
	}

	public function resolveStateId(?string $locationStateHint, string $description, string $title): ?int
	{
		$hint = $this->normalizeHint($locationStateHint);
		if ($hint === '') {
			$hint = $this->guessUsStateAbbreviationFromText($title . ' ' . $description);
		}
		if ($hint === '') {
			return null;
		}

		$rows = $this->cachedStates();
		if ($rows->isEmpty()) {
			return null;
		}

		return $this->bestStateId($hint, $rows);
	}

	private function normalizeHint(?string $s): string
	{
		if ($s === null) {
			return '';
		}
		$s = trim(strip_tags($s));
		if ($s === '' || strcasecmp($s, 'Not provided') === 0 || strcasecmp($s, 'N/A') === 0) {
			return '';
		}

		return $s;
	}

	private function categoryHintFromText(string $text): string
	{
		if (preg_match('/commodity\s*[\/:]\s*(.+)/i', $text, $m)) {
			return trim($m[1]);
		}
		if (preg_match('/category\s*[\/:]\s*(.+)/i', $text, $m)) {
			return trim(explode("\n", $m[1])[0]);
		}

		return '';
	}

	private function cachedCategories(): Collection
	{
		$table = (string) $this->cfg('category_table', 'category');
		$idCol = (string) $this->cfg('category_id_column', 'id');
		$nameCol = (string) $this->cfg('category_name_column', 'name');
		$key = 'scraper.categories.' . md5($table . $idCol . $nameCol);

		return Cache::remember($key, 3600, function () use ($table, $idCol, $nameCol) {
			try {
				return collect(DB::table($table)->select([$idCol, $nameCol])->get());
			} catch (\Throwable $e) {
				Log::warning('BidReferenceLookup: CATEGORY table unreadable', [
					'table' => $table,
					'error' => $e->getMessage(),
				]);

				return collect();
			}
		});
	}

	private function cachedStates(): Collection
	{
		$table = (string) $this->cfg('state_table', 'state');
		$idCol = (string) $this->cfg('state_id_column', 'id');
		$nameCol = (string) $this->cfg('state_name_column', 'name');
		$abbrCol = (string) $this->cfg('state_abbr_column', 'abbreviation');
		$key = 'scraper.states.' . md5($table . $idCol . $nameCol . $abbrCol);

		return Cache::remember($key, 3600, function () use ($table, $idCol, $nameCol, $abbrCol) {
			try {
				return collect(DB::table($table)->select([$idCol, $nameCol, $abbrCol])->get());
			} catch (\Throwable $e) {
				Log::warning('BidReferenceLookup: STATE table unreadable', [
					'table' => $table,
					'error' => $e->getMessage(),
				]);

				return collect();
			}
		});
	}

	private function bestCategoryId(string $hint, Collection $rows): ?int
	{
		$idCol = (string) $this->cfg('category_id_column', 'id');
		$nameCol = (string) $this->cfg('category_name_column', 'name');
		$hintLower = mb_strtolower($hint);

		$bestId = null;
		$bestScore = 0.0;

		foreach ($rows as $row) {
			$id = $this->rowAttr($row, $idCol);
			$name = $this->rowAttr($row, $nameCol);
			$name = $name !== null && $name !== '' ? trim((string) $name) : '';
			if ($id === null || $name === '') {
				continue;
			}
			$nameLower = mb_strtolower($name);
			if ($hintLower === $nameLower) {
				return (int) $id;
			}
			$score = 0.0;
			if (str_contains($nameLower, $hintLower) || str_contains($hintLower, $nameLower)) {
				$score = 80.0;
			} else {
				similar_text($hintLower, $nameLower, $pct);
				$score = (float) $pct;
			}
			if ($score > $bestScore && $score >= 50.0) {
				$bestScore = $score;
				$bestId = (int) $id;
			}
		}

		return $bestId;
	}

	private function bestStateId(string $hint, Collection $rows): ?int
	{
		$idCol = (string) $this->cfg('state_id_column', 'id');
		$nameCol = (string) $this->cfg('state_name_column', 'name');
		$abbrCol = (string) $this->cfg('state_abbr_column', 'abbreviation');
		$h = trim($hint);
		$hUpper = strtoupper($h);
		if (strlen($hUpper) === 2 && ctype_alpha($hUpper)) {
			foreach ($rows as $row) {
				$abbrRaw = $this->rowAttr($row, $abbrCol);
				$abbr = $abbrRaw !== null && $abbrRaw !== '' ? strtoupper(trim((string) $abbrRaw)) : '';
				if ($abbr !== '' && $abbr === $hUpper) {
					$idVal = $this->rowAttr($row, $idCol);

					return $idVal !== null && $idVal !== '' ? (int) $idVal : null;
				}
			}
		}
		$hLower = mb_strtolower($h);
		foreach ($rows as $row) {
			$nameRaw = $this->rowAttr($row, $nameCol);
			$name = $nameRaw !== null && $nameRaw !== '' ? trim((string) $nameRaw) : '';
			if ($name === '') {
				continue;
			}
			if (mb_strtolower($name) === $hLower) {
				$idVal = $this->rowAttr($row, $idCol);

				return $idVal !== null && $idVal !== '' ? (int) $idVal : null;
			}
		}
		foreach ($rows as $row) {
			$nameRaw = $this->rowAttr($row, $nameCol);
			$idVal = $this->rowAttr($row, $idCol);
			$name = $nameRaw !== null && $nameRaw !== '' ? trim((string) $nameRaw) : '';
			if ($name === '' || $idVal === null || $idVal === '') {
				continue;
			}
			$nameLower = mb_strtolower($name);
			if (str_contains($nameLower, $hLower) || str_contains($hLower, $nameLower)) {
				return (int) $idVal;
			}
		}

		return null;
	}

	/** Best-effort 2-letter US state from all-caps tokens in text. */
	private function guessUsStateAbbreviationFromText(string $text): string
	{
		$valid = [
			'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA',
			'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM',
			'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA',
			'WV', 'WI', 'WY',
		];
		$validSet = array_flip($valid);
		if (preg_match_all('/\b([A-Z]{2})\b/', $text, $m)) {
			foreach ($m[1] as $abbr) {
				if (isset($validSet[$abbr])) {
					return $abbr;
				}
			}
		}

		return '';
	}
}
