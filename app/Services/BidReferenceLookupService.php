<?php

namespace App\Services;

use App\Support\EntityNameMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BidReferenceLookupService
{
	/** @var array<string, int|null> Per-request memo of resolveEntityId results (same Bid URL repeats across its bids). */
	private array $entityResolveMemo = [];

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
		string $description,
		?string $sourceListingUrl = null,
		?string $bidUrlName = null
	): ?int {
		if (!$this->cfg('entity_resolve_enabled', true)) {
			return null;
		}

		$index = $this->cachedEntityMatchIndex();
		if ($index['names'] === [] && $index['email'] === [] && $index['domain'] === []) {
			return null;
		}

		$memoKey = md5(json_encode([
			$bidUrlName,
			$resolvedBidEmail,
			$bidDetailUrl,
			$sourceListingUrl,
			$issuingOrganizationHint,
			mb_substr($title, 0, 120),
		]));
		if (array_key_exists($memoKey, $this->entityResolveMemo)) {
			return $this->entityResolveMemo[$memoKey];
		}

		$result = $this->resolveEntityIdFromIndex(
			$index,
			$issuingOrganizationHint,
			$resolvedBidEmail,
			$bidDetailUrl,
			$title,
			$description,
			$sourceListingUrl,
			$bidUrlName
		);

		return $this->entityResolveMemo[$memoKey] = $result;
	}

	/**
	 * @param array{email: array<string, list<int>>, domain: array<string, list<int>>, canon: array<string, list<int>>, names: list<array{id:int, lower:string, canon:string, tokens:list<string>}>} $index
	 */
	private function resolveEntityIdFromIndex(
		array $index,
		?string $issuingOrganizationHint,
		?string $resolvedBidEmail,
		string $bidDetailUrl,
		string $title,
		string $description,
		?string $sourceListingUrl,
		?string $bidUrlName
	): ?int {
		$bidUrlNameHint = $this->normalizeOrganizationHintText($bidUrlName);
		if ($bidUrlNameHint !== '') {
			$fromBidUrlName = $this->bestEntityIdFromNames($index['names'], [$bidUrlNameHint], 72.0);
			if ($fromBidUrlName !== null) {
				Log::info('Entity matched from Bid URL name', [
					'entity_id' => $fromBidUrlName,
					'bid_url_name' => $bidUrlNameHint,
				]);

				return $fromBidUrlName;
			}
		}

		$emailComparable = $this->normalizeComparableEmail($resolvedBidEmail);
		if ($emailComparable !== '' && isset($index['email'][$emailComparable])) {
			$entityId = min($index['email'][$emailComparable]);
			Log::info('Entity matched from bid contact email', ['entity_id' => $entityId, 'email' => $emailComparable]);

			return $entityId;
		}

		foreach (EntityNameMatch::meaningfulUrlHosts($bidDetailUrl, (string) $sourceListingUrl) as $bidHost) {
			$ids = $this->entityIdsForHost($index['domain'], $bidHost);
			if ($ids !== []) {
				$entityId = min($ids);
				Log::info('Entity matched from URL host', ['entity_id' => $entityId, 'host' => $bidHost]);

				return $entityId;
			}
		}

		$nameHints = $this->collectEntityNameHints(
			$issuingOrganizationHint,
			$title,
			$description,
			$bidUrlName,
			$sourceListingUrl,
			$bidDetailUrl
		);
		if ($nameHints === []) {
			return null;
		}

		$entityId = $this->bestEntityIdFromNames($index['names'], $nameHints, 78.0);
		if ($entityId !== null) {
			Log::info('Entity matched from organization name hints', [
				'entity_id' => $entityId,
				'hints' => array_slice($nameHints, 0, 4),
			]);
		}

		return $entityId;
	}

	/**
	 * Entity ids whose email-domain or website host matches the bid host (or a parent suffix of it).
	 *
	 * @param array<string, list<int>> $domainMap
	 * @return list<int>
	 */
	private function entityIdsForHost(array $domainMap, string $host): array
	{
		$host = EntityNameMatch::normalizeUrlHost($host);
		if ($host === '') {
			return [];
		}

		$labels = explode('.', $host);
		$ids = [];
		// Suffixes with at least 2 labels (so we never match a bare TLD).
		for ($i = 0; $i <= count($labels) - 2; $i++) {
			$suffix = implode('.', array_slice($labels, $i));
			if (isset($domainMap[$suffix])) {
				foreach ($domainMap[$suffix] as $id) {
					$ids[] = $id;
				}
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Precomputed lookup maps for entity matching, cached so per-bid resolution is cheap.
	 *
	 * @return array{email: array<string, list<int>>, domain: array<string, list<int>>, canon: array<string, list<int>>, names: list<array{id:int, lower:string, canon:string, tokens:list<string>}>}
	 */
	private function cachedEntityMatchIndex(): array
	{
		$empty = ['email' => [], 'domain' => [], 'canon' => [], 'names' => []];

		$spec = $this->entitySelectableSpec();
		if ($spec === null) {
			return $empty;
		}

		$key = 'scraper.entity_match_index.' . md5(json_encode([
			$spec['table'],
			$spec['cols'],
			$spec['id_col'],
			$spec['email_cols'],
			$spec['name_cols'],
			$spec['web_cols'],
		]));

		return Cache::remember($key, 3600, function () use ($spec, $empty) {
			$rows = $this->cachedEntities();
			if ($rows->isEmpty()) {
				return $empty;
			}

			$idCol = $spec['id_col'];
			$emailCols = $spec['email_cols'];
			$nameCols = $spec['name_cols'];
			$webCols = $spec['web_cols'];

			$email = [];
			$domain = [];
			$canon = [];
			$names = [];

			foreach ($rows as $row) {
				$idRaw = $this->rowAttr($row, $idCol);
				if ($idRaw === null || $idRaw === '') {
					continue;
				}
				$id = (int) $idRaw;

				foreach ($this->rowEmailValues($row, $emailCols) as $e) {
					$email[$e][] = $id;
					$d = $this->emailDomain($e);
					if ($d !== '') {
						$domain[$d][] = $id;
					}
				}

				foreach ($webCols as $webCol) {
					$urlRaw = $this->rowAttr($row, $webCol);
					if ($urlRaw === null || trim((string) $urlRaw) === '') {
						continue;
					}
					$wh = EntityNameMatch::normalizeUrlHost((string) $urlRaw);
					if ($wh !== '') {
						$domain[$wh][] = $id;
					}
				}

				foreach ($nameCols as $ncol) {
					$nameRaw = $this->rowAttr($row, $ncol);
					$nameRaw = $nameRaw !== null && $nameRaw !== '' ? trim((string) $nameRaw) : '';
					if ($nameRaw === '' || mb_strlen($nameRaw) < 3) {
						continue;
					}
					$ck = EntityNameMatch::canonicalKey($nameRaw);
					if ($ck !== '') {
						$canon[$ck][] = $id;
					}
					$names[] = [
						'id' => $id,
						'lower' => mb_strtolower($nameRaw),
						'canon' => $ck,
						'tokens' => EntityNameMatch::significantTokens($nameRaw),
					];
				}
			}

			$dedupe = static function (array $map): array {
				foreach ($map as $k => $ids) {
					$map[$k] = array_values(array_unique($ids));
				}

				return $map;
			};

			return [
				'email' => $dedupe($email),
				'domain' => $dedupe($domain),
				'canon' => $dedupe($canon),
				'names' => $names,
			];
		});
	}

	/**
	 * Best entity id for name hints using precomputed entity name entries (no per-row regex).
	 *
	 * @param list<array{id:int, lower:string, canon:string, tokens:list<string>}> $names
	 * @param array<int, string> $hints
	 */
	private function bestEntityIdFromNames(array $names, array $hints, float $threshold): ?int
	{
		if ($names === []) {
			return null;
		}

		$prepared = [];
		foreach ($hints as $hint) {
			$h = trim((string) $hint);
			if ($h === '' || mb_strlen($h) < 4 || mb_strlen($h) > 220) {
				continue;
			}
			$prepared[] = [
				'lower' => mb_strtolower($h),
				'canon' => EntityNameMatch::canonicalKey($h),
				'tokens' => EntityNameMatch::significantTokens($h),
			];
		}
		if ($prepared === []) {
			return null;
		}

		/** @var array<int, float> $scores */
		$scores = [];

		foreach ($names as $entry) {
			$id = $entry['id'];
			$nameLower = $entry['lower'];
			$nameCanon = $entry['canon'];

			foreach ($prepared as $p) {
				if ($p['canon'] !== '' && $p['canon'] === $nameCanon) {
					return $id;
				}
				if ($p['lower'] === $nameLower) {
					return $id;
				}

				similar_text($p['lower'], $nameLower, $pct);
				$pct = (float) $pct;

				if (mb_strlen($p['lower']) >= 8 && (str_contains($nameLower, $p['lower']) || str_contains($p['lower'], $nameLower))) {
					$pct = max($pct, 88.0);
				}

				$pct = max($pct, EntityNameMatch::tokenOverlapTokens($p['tokens'], $entry['tokens']));

				$scores[$id] = max($scores[$id] ?? 0.0, $pct);
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

	/**
	 * Entity master table + column mapping for selectable queries / caches.
	 *
	 * @return array{table:string, id_col:string, email_cols:array<int, string>, name_cols:array<int, string>, web_cols:array<int, string>, cols:array<int, string>}|null
	 */
	private function entitySelectableSpec(): ?array
	{
		$table = trim((string) $this->cfg('entity_table', 'entity'));
		$idCol = trim((string) $this->cfg('entity_id_column', 'id'));
		if ($table === '' || $idCol === '') {
			return null;
		}

		$emailCols = array_values(array_unique(array_filter((array) $this->cfg('entity_email_columns', ['email']), fn ($c) => (string) trim((string) $c) !== '')));
		$nameCols = array_values(array_unique(array_filter((array) $this->cfg('entity_name_columns', ['name']), fn ($c) => (string) trim((string) $c) !== '')));
		$webCols = array_values(array_unique(array_filter((array) $this->cfg('entity_website_columns', []), fn ($c) => (string) trim((string) $c) !== '')));
		$cols = array_values(array_unique(array_filter(array_merge([$idCol], $emailCols, $nameCols, $webCols), fn ($c) => (string) trim((string) $c) !== '')));
		if ($cols === []) {
			return null;
		}

		return [
			'table' => $table,
			'id_col' => $idCol,
			'email_cols' => $emailCols,
			'name_cols' => $nameCols,
			'web_cols' => $webCols,
			'cols' => $cols,
		];
	}

	/**
	 * One row formatted for bid edit autocomplete (resolve display label by id).
	 *
	 * @return array{id: int|string, label: string}|null
	 */
	public function getEntityOptionById(int $entityId): ?array
	{
		if (!$this->cfg('entity_resolve_enabled', true) || $entityId < 1) {
			return null;
		}

		$spec = $this->entitySelectableSpec();
		if ($spec === null) {
			return null;
		}

		try {
			$row = DB::table($spec['table'])
				->select($spec['cols'])
				->where($spec['id_col'], $entityId)
				->first();
		} catch (\Throwable $e) {
			Log::warning('BidReferenceLookup: entity lookup failed', [
				'table' => $spec['table'],
				'error' => $e->getMessage(),
			]);

			return null;
		}

		if (!is_object($row)) {
			return null;
		}

		return $this->entityRowToSelectOption($row, $spec['id_col'], $spec['name_cols'], $spec['email_cols']);
	}

	/**
	 * Typeahead rows for ENTITYID on bid edit modal.
	 *
	 * @return array<int, array{id: int|string, label: string}>
	 */
	public function searchEntitiesForSelect(string $query, int $limit = 40): array
	{
		if (!$this->cfg('entity_resolve_enabled', true)) {
			return [];
		}

		$spec = $this->entitySelectableSpec();
		if ($spec === null) {
			return [];
		}

		$limit = max(5, min(100, $limit));
		$table = $spec['table'];
		$idCol = $spec['id_col'];
		$nameCols = $spec['name_cols'];
		$emailCols = $spec['email_cols'];

		try {
			$builder = DB::table($table)->select($spec['cols']);
			$needle = preg_replace('/[%_\\\\]/', '', mb_substr(trim($query), 0, 160));

			if ($needle !== '') {
				$builder->where(function ($w) use ($needle, $idCol, $nameCols, $emailCols) {
					foreach ($nameCols as $col) {
						$w->orWhere($col, 'like', '%' . $needle . '%');
					}
					foreach ($emailCols as $col) {
						$w->orWhere($col, 'like', '%' . $needle . '%');
					}
					if (preg_match('/^\d+$/', trim($needle))) {
						$w->orWhere($idCol, (int) $needle);
					}
				});
			}

			$builder->orderBy($idCol);
			$rows = collect($builder->limit($limit)->get());
		} catch (\Throwable $e) {
			Log::warning('BidReferenceLookup: entity search failed', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$opt = $this->entityRowToSelectOption($row, $idCol, $nameCols, $emailCols);
			if ($opt !== null) {
				$out[] = $opt;
			}
		}

		return $out;
	}

	/**
	 * One row formatted for state autocomplete (resolve display label by id).
	 *
	 * @return array{id: int|string, label: string}|null
	 */
	public function getStateOptionById(int $stateId): ?array
	{
		if ($stateId < 1) {
			return null;
		}

		$idCol = (string) $this->cfg('state_id_column', 'id');
		foreach ($this->cachedStates() as $row) {
			$idRaw = $this->rowAttr($row, $idCol);
			if ($idRaw === null || $idRaw === '' || (int) $idRaw !== $stateId) {
				continue;
			}

			return $this->stateRowToSelectOption($row);
		}

		return null;
	}

	/**
	 * Typeahead rows for STATEID on bid / pending edit forms.
	 *
	 * @return array<int, array{id: int|string, label: string}>
	 */
	public function searchStatesForSelect(string $query, int $limit = 50): array
	{
		$limit = max(5, min(100, $limit));
		$needle = mb_strtolower(preg_replace('/[%_\\\\]/', '', mb_substr(trim($query), 0, 80)));
		$rows = $this->cachedStates();
		if ($rows->isEmpty()) {
			return [];
		}

		$idCol = (string) $this->cfg('state_id_column', 'id');
		$nameCol = (string) $this->cfg('state_name_column', 'name');
		$abbrCol = (string) $this->cfg('state_abbr_column', 'abbreviation');

		$out = [];
		foreach ($rows as $row) {
			$opt = $this->stateRowToSelectOption($row);
			if ($opt === null) {
				continue;
			}
			if ($needle === '') {
				$out[] = $opt;

				continue;
			}
			$name = mb_strtolower(trim((string) ($this->rowAttr($row, $nameCol) ?? '')));
			$abbr = mb_strtolower(trim((string) ($this->rowAttr($row, $abbrCol) ?? '')));
			$idStr = (string) ($this->rowAttr($row, $idCol) ?? '');
			if (
				($name !== '' && str_contains($name, $needle))
				|| ($abbr !== '' && str_contains($abbr, $needle))
				|| ($idStr !== '' && $idStr === trim($query))
			) {
				$out[] = $opt;
			}
		}

		usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return array_slice($out, 0, $limit);
	}

	/**
	 * @return array{id: int|string, label: string}|null
	 */
	private function stateRowToSelectOption(object $row): ?array
	{
		$idCol = (string) $this->cfg('state_id_column', 'id');
		$nameCol = (string) $this->cfg('state_name_column', 'name');
		$abbrCol = (string) $this->cfg('state_abbr_column', 'abbreviation');

		$idRaw = $this->rowAttr($row, $idCol);
		if ($idRaw === null || $idRaw === '') {
			return null;
		}

		$name = trim((string) ($this->rowAttr($row, $nameCol) ?? ''));
		$abbr = strtoupper(trim((string) ($this->rowAttr($row, $abbrCol) ?? '')));
		if ($name === '' && $abbr === '') {
			return null;
		}

		$label = $abbr !== '' && $name !== ''
			? $abbr . ' — ' . $name
			: ($name !== '' ? $name : $abbr);

		return [
			'id' => is_numeric($idRaw) ? (int) $idRaw : (string) $idRaw,
			'label' => $label,
		];
	}

	/**
	 * @param array<int, string> $nameCols
	 * @param array<int, string> $emailCols
	 * @return array{id: int|string, label: string}|null
	 */
	private function entityRowToSelectOption(object $row, string $idCol, array $nameCols, array $emailCols): ?array
	{
		$idRaw = $this->rowAttr($row, $idCol);
		if ($idRaw === null || $idRaw === '') {
			return null;
		}

		$nameChunks = [];
		foreach ($nameCols as $col) {
			$v = $this->rowAttr($row, $col);
			if ($v === null) {
				continue;
			}
			$t = trim(preg_replace('/\s+/u', ' ', (string) $v));
			if ($t !== '') {
				$nameChunks[$t] = true;
			}
		}
		$name = implode(' ', array_keys($nameChunks));
		if ($name === '') {
			$name = 'Entity';
		}

		$emailShow = '';
		foreach ($emailCols as $col) {
			$v = $this->rowAttr($row, $col);
			if ($v === null) {
				continue;
			}
			$t = strtolower(trim(strip_tags((string) $v)));
			if ($t !== '' && filter_var($t, FILTER_VALIDATE_EMAIL)) {
				$emailShow = $t;

				break;
			}
		}

		$labelSuffix = $emailShow !== '' ? ' · ' . $emailShow : '';

		return [
			'id' => is_numeric($idRaw) ? (int) $idRaw : (string) $idRaw,
			'label' => $name . ' (#' . (string) $idRaw . ')' . $labelSuffix,
		];
	}

	private function cachedEntities(): Collection
	{
		$spec = $this->entitySelectableSpec();
		if ($spec === null) {
			return collect();
		}

		$table = $spec['table'];
		$cols = $spec['cols'];

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

	/** @return array<int, string> */
	private function collectEntityNameHints(
		?string $issuingOrganizationHint,
		string $title,
		string $description,
		?string $bidUrlName = null,
		?string $sourceListingUrl = null,
		?string $bidDetailUrl = null
	): array {
		$list = [];

		foreach ([$bidUrlName, $issuingOrganizationHint] as $h) {
			$x = $this->normalizeOrganizationHintText($h);
			if ($x !== '') {
				$list[] = $x;
			}
		}

		foreach ([$sourceListingUrl, $bidDetailUrl] as $url) {
			$subHint = EntityNameMatch::organizationHintFromSubdomain((string) $url);
			if ($subHint !== '') {
				$list[] = $subHint;
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
		$s = EntityNameMatch::stripPortalVendorNames($s);
		if ($s === '' || mb_strlen($s) < 3) {
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
			'/\bIssuing\s+Organization\s*:\s*([^\r\n]{3,240})/iu',
			'/\bBuyer\s*:\s*([^\r\n]{3,240})/iu',
			'/\bProcuring\s+[Ee]ntity\s*:\s*([^\r\n]{3,240})/iu',
			'/\bDepartment\s*:\s*([^\r\n]{3,240})/iu',
			'/\bSchool\s+District\s*:\s*([^\r\n]{3,240})/iu',
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
