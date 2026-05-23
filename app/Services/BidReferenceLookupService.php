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
			$firstParts = array_filter([
				$upper['FIRSTNAME'] ?? $upper['FIRST_NAME'] ?? null,
				$upper['LASTNAME'] ?? $upper['LAST_NAME'] ?? null,
			], fn ($v) => $v !== null && trim((string) $v) !== '');
			$label = trim(implode(' ', array_map('trim', $firstParts)));
			if ($label === '') {
				$nameCol = isset($upper['NAME']) ? trim((string) $upper['NAME']) : '';
				$label = $nameCol !== '' ? $nameCol : 'Unnamed';
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

	/**
	 * Match scraped bid data against the configured entity master list (email, URL/domain, then org name hints).
	 * Returns ENTITY id or null when no confident match exists.
	 */
	public function resolveEntityId(
		?string $issuingOrganizationHint,
		?string $resolvedBidEmail,
		string $bidDetailUrl,
		string $title,
		string $description
	): ?int {
		if (!$this->cfg('entity_resolve_enabled', true)) {
			return null;
		}

		$rows = $this->cachedEntities();
		if ($rows->isEmpty()) {
			return null;
		}

		$idCol = (string) $this->cfg('entity_id_column', 'id');
		$emailCols = array_values(array_filter($this->cfg('entity_email_columns', ['email']), fn ($c) => (string) $c !== ''));
		$nameCols = array_values(array_filter($this->cfg('entity_name_columns', ['name']), fn ($c) => (string) $c !== ''));
		$webCols = array_values(array_filter($this->cfg('entity_website_columns', []), fn ($c) => (string) $c !== ''));
		if ($emailCols === [] && $nameCols === [] && $webCols === []) {
			return null;
		}

		$emailComparable = $this->normalizeComparableEmail($resolvedBidEmail);
		if ($emailComparable !== '') {
			$ids = [];
			foreach ($rows as $row) {
				foreach ($this->rowEmailValues($row, $emailCols) as $e) {
					if ($emailComparable === $e) {
						$idRaw = $this->rowAttr($row, $idCol);
						if ($idRaw !== null && $idRaw !== '') {
							$ids[] = (int) $idRaw;
						}
						break;
					}
				}
			}
			if ($ids !== []) {
				return min(array_unique($ids));
			}
		}

		$bidHost = $this->normalizeUrlHost($bidDetailUrl);
		if ($bidHost !== '') {
			$ids = [];

			foreach ($rows as $row) {
				$idRaw = $this->rowAttr($row, $idCol);
				if ($idRaw === null || $idRaw === '') {
					continue;
				}

				foreach ($this->rowEmailValues($row, $emailCols) as $fullEmail) {
					$domain = $this->emailDomain($fullEmail);
					if ($domain !== '' && $this->hostnameBelongsToRegistrableDomain($bidHost, $domain)) {
						$ids[] = (int) $idRaw;
						continue 2;
					}
				}

				foreach ($webCols as $webCol) {
					$urlRaw = $this->rowAttr($row, $webCol);
					if ($urlRaw === null || trim((string) $urlRaw) === '') {
						continue;
					}
					$wh = $this->normalizeUrlHost((string) $urlRaw);
					if ($wh !== '' && ($bidHost === $wh || str_ends_with($bidHost, '.' . $wh) || str_ends_with($wh, '.' . $bidHost))) {
						$ids[] = (int) $idRaw;
						continue 2;
					}
				}
			}

			if ($ids !== []) {
				return min(array_unique($ids));
			}
		}

		$nameHints = $this->collectEntityNameHints($issuingOrganizationHint, $title, $description);
		if ($nameHints === []) {
			return null;
		}

		return $this->bestEntityIdByNameHints($rows, $idCol, $nameCols, $nameHints);
	}

	private function cachedEntities(): Collection
	{
		$table = (string) $this->cfg('entity_table', 'entity');
		$idCol = (string) $this->cfg('entity_id_column', 'id');
		$emailCols = array_values(array_unique(array_filter((array) $this->cfg('entity_email_columns', ['email']), fn ($c) => (string) trim((string) $c) !== '')));
		$nameCols = array_values(array_unique(array_filter((array) $this->cfg('entity_name_columns', ['name']), fn ($c) => (string) trim((string) $c) !== '')));
		$webCols = array_values(array_unique(array_filter((array) $this->cfg('entity_website_columns', []), fn ($c) => (string) trim((string) $c) !== '')));
		$cols = array_values(array_unique(array_filter(array_merge([$idCol], $emailCols, $nameCols, $webCols), fn ($c) => (string) trim((string) $c) !== '')));

		$key = 'scraper.entities.' . md5(json_encode(compact('table', 'cols')));

		return Cache::remember($key, 3600, function () use ($table, $cols) {
			try {
				return collect(DB::table($table)->select($cols)->get());
			} catch (\Throwable $e) {
				Log::warning('BidReferenceLookup: ENTITY table unreadable', [
					'table' => $table,
					'error' => $e->getMessage(),
				]);

				return collect();
			}
		});
	}

	/** @param array<int, string> $emailCols */
	private function rowEmailValues(object $row, array $emailCols): array
	{
		$out = [];
		foreach ($emailCols as $col) {
			$val = $this->rowAttr($row, $col);
			if ($val === null) {
				continue;
			}
			$n = $this->normalizeComparableEmail((string) $val);
			if ($n !== '') {
				$out[] = $n;
			}
		}

		return array_values(array_unique($out));
	}

	private function normalizeComparableEmail(?string $email): string
	{
		if ($email === null) {
			return '';
		}
		$s = strtolower(trim(strip_tags($email)));
		if ($s === '' || str_contains($s, 'not provided')) {
			return '';
		}

		return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
	}

	private function emailDomain(string $comparableEmail): string
	{
		$i = strpos($comparableEmail, '@');
		if ($i === false) {
			return '';
		}
		$domain = strtolower(trim(substr($comparableEmail, $i + 1)));

		return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : '';
	}

	private function normalizeUrlHost(string $url): string
	{
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*:/#i', $url)) {
			$url = 'https://' . $url;
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!$host || !is_string($host)) {
			return '';
		}
		$host = strtolower($host);

		return preg_replace('/^www\./', '', $host);
	}

	private function hostnameBelongsToRegistrableDomain(string $host, string $domain): bool
	{
		$domain = strtolower($domain);
		$host = strtolower($host);
		if ($host === '') {
			return false;
		}
		if ($host === $domain) {
			return true;
		}

		return str_ends_with($host, '.' . $domain);
	}

	/** @return array<int, string> */
	private function collectEntityNameHints(?string $issuingOrganizationHint, string $title, string $description): array
	{
		$list = [];

		foreach ([$issuingOrganizationHint] as $h) {
			$x = $this->normalizeOrganizationHintText($h);
			if ($x !== '') {
				$list[] = $x;
			}
		}

		foreach ($this->guessOrganizationFragmentsFromStructuredText($title . "\n" . $description) as $frag) {
			$list[] = $frag;
		}

		$out = [];
		foreach ($list as $entry) {
			$norm = mb_strtolower(trim((string) $entry));
			if ($norm !== '' && !in_array($norm, array_map(static fn ($e) => mb_strtolower($e), $out), true)) {
				$out[] = trim((string) $entry);
			}
		}

		return $out;
	}

	private function normalizeOrganizationHintText(?string $s): string
	{
		if ($s === null) {
			return '';
		}
		$s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
		if ($s === '' || strcasecmp($s, 'Not provided') === 0 || strcasecmp($s, 'N/A') === 0) {
			return '';
		}
		if (mb_strlen($s) < 3) {
			return '';
		}

		return $s;
	}

	/**
	 * @return array<int, string>
	 */
	private function guessOrganizationFragmentsFromStructuredText(string $blob): array
	{
		if ($blob === '') {
			return [];
		}
		$patterns = [
			'/\bAwarding\s+Agency\s*:\s*([^\r\n]{3,240})/iu',
			'/\bAgency\s*:\s*([^\r\n]{3,240})/iu',
			'/\bIssuing\s+organization\s*:\s*([^\r\n]{3,240})/iu',
			'/\bBuyer\s*:\s*([^\r\n]{3,240})/iu',
			'/\bProcuring\s+[Ee]ntity\s*:\s*([^\r\n]{3,240})/iu',
		];
		$candidates = [];
		foreach ($patterns as $re) {
			if (!preg_match($re, $blob, $m)) {
				continue;
			}
			$t = trim($m[1]);
			foreach (preg_split('/[|;]/', $t) as $chunk) {
				$chunk = trim($chunk);
				if (mb_strlen($chunk) >= 4) {
					$candidates[] = $chunk;
				}
			}
		}

		return $candidates;
	}

	/**
	 * @param array<int, string> $nameCols
	 * @param array<int, string> $nameHints
	 */
	private function bestEntityIdByNameHints(Collection $rows, string $idCol, array $nameCols, array $nameHints): ?int
	{
		if ($nameCols === []) {
			return null;
		}

		$threshold = 78.0;
		/** @var array<int, float> $scores */
		$scores = [];

		foreach ($rows as $row) {
			$idRaw = $this->rowAttr($row, $idCol);
			if ($idRaw === null || $idRaw === '') {
				continue;
			}
			$idInt = (int) $idRaw;

			foreach ($nameCols as $ncol) {
				$nameRaw = $this->rowAttr($row, $ncol);
				$nameRaw = $nameRaw !== null && $nameRaw !== '' ? trim((string) $nameRaw) : '';
				if ($nameRaw === '' || mb_strlen($nameRaw) < 3) {
					continue;
				}
				$nameLower = mb_strtolower($nameRaw);

				foreach ($nameHints as $hint) {
					$h = trim($hint);
					if ($h === '') {
						continue;
					}
					if (mb_strlen($h) < 4 || mb_strlen($h) > 220) {
						continue;
					}
					$hLower = mb_strtolower($h);

					if ($hLower === $nameLower) {
						return $idInt;
					}

					similar_text($hLower, $nameLower, $pct);
					$pct = (float) $pct;

					$strippedCompare = mb_strlen($hLower) >= 8 && (
						str_contains($nameLower, $hLower)
						|| str_contains($hLower, $nameLower)
						|| str_contains(str_replace([',', '.'], '', $nameLower), str_replace([',', '.'], '', $hLower))
					);
					if ($strippedCompare) {
						$pct = max($pct, 88.0);
					}

					$scores[$idInt] = max($scores[$idInt] ?? 0.0, $pct);
				}
			}
		}

		if ($scores === []) {
			return null;
		}

		$bestScore = max($scores);
		if ($bestScore < $threshold) {
			return null;
		}

		$tied = [];
		foreach ($scores as $id => $sc) {
			if ($sc >= $threshold && abs($sc - $bestScore) < 1e-5) {
				$tied[] = (int) $id;
			}
		}

		sort($tied);

		return count($tied) === 1 ? $tied[0] : null;
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
