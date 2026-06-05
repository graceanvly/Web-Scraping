<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Pending Approval - Bid Scraper</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		html, body {
			margin: 0; padding: 0; min-height: 100vh;
			background: linear-gradient(135deg, #f5f7fa 0%, #e4ebf5 100%);
			font-family: system-ui, sans-serif;
		}
		nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem; }
		h1 { margin: 0; font-size: 1.8rem; font-weight: 600; }
		.card { background: #fff; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 2rem; }
		table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
		th, td { padding: 0.7rem 0.9rem; text-align: left; vertical-align: top; font-size: 0.88rem; }
		thead { background: #f0f2f5; font-weight: 600; color: #1d4ed8; }
		tbody tr:nth-child(even) { background: #fafafa; }
		tbody tr:hover { background: #f1f5f9; }
		.alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid transparent; font-size: 0.95rem; }
		.alert.success { background: #ecfdf3; border-color: #bbf7d0; color: #166534; }
		.alert.error { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
		.muted { color: #6b7280; }
		.entity-pill { display:inline-block; padding:0.1rem 0.5rem; border-radius:999px; font-size:0.72rem; font-weight:600; }
		.entity-pill.has { background:#ecfdf3; color:#166534; border:1px solid #bbf7d0; }
		.entity-pill.none { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
		.row-actions { display:flex; gap:0.4rem; flex-wrap:nowrap; align-items:center; justify-content:flex-end; }
		.icon-action {
			display:inline-flex; align-items:center; justify-content:center;
			width:2rem; height:2rem; padding:0; margin:0; min-width:auto;
			border-radius:6px; border:1px solid transparent; cursor:pointer;
			background:transparent;
		}
		.icon-action svg { width:1.1rem; height:1.1rem; }
		.icon-action--approve { color:#16a34a; border-color:#bbf7d0; background:#f0fdf4; }
		.icon-action--approve:hover { background:#dcfce7; }
		.icon-action--reject { color:#b91c1c; border-color:#fecaca; background:#fef2f2; }
		.icon-action--reject:hover { background:#fee2e2; }
		.title-cell { max-width: 420px; overflow-wrap:anywhere; }
		.title-cell-inner { display:flex; align-items:flex-start; gap:0.35rem; }
		.title-edit-trigger {
			background:none; border:none; padding:0; margin:0; min-width:0;
			color:#2563eb; cursor:pointer; text-align:left; font:inherit; font-weight:500;
			text-decoration:underline; text-underline-offset:2px;
		}
		.title-edit-trigger:hover { color:#1d4ed8; }
		a.title-external-link {
			display:inline-flex; flex-shrink:0; color:#64748b; margin-top:0.1rem;
		}
		a.title-external-link:hover { color:#2563eb; }
		a.title-external-link svg { width:0.95rem; height:0.95rem; }
		.naics-cell { white-space:nowrap; font-variant-numeric:tabular-nums; }
		.toolbar { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; }
		dialog { max-width: 640px; width: 92%; border:none; border-radius:12px; padding:0; box-shadow:0 8px 30px rgba(0,0,0,0.15); }
		dialog article { margin:0; padding:1.75rem; }
		.edit-grid { display:grid; grid-template-columns: 1fr 1fr; gap:0.9rem; }
		.edit-grid .full { grid-column: 1 / -1; }
		.edit-grid label { font-size:0.82rem; font-weight:600; color:#374151; margin-bottom:0.2rem; display:block; }
		.ref-picker { position:relative; }
		.ref-picker-inner input[type="search"] { margin-bottom:0.15rem; }
		.ref-picker-results {
			position:absolute; left:0; right:0; top:100%; z-index:30; margin:0.2rem 0 0; padding:0; list-style:none;
			background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-height:240px; overflow-y:auto;
			box-shadow:0 6px 18px rgba(0,0,0,0.12);
		}
		.ref-picker-results li { padding:0.5rem 0.7rem; cursor:pointer; font-size:0.85rem; }
		.ref-picker-results li.ref-res-active, .ref-picker-results li:hover { background:#eff6ff; }
		.ref-picker-clear {
			margin:0; padding:0; border:none; background:none; cursor:pointer;
			color:#2563eb; text-decoration:underline; font-size:0.82rem;
		}
		.ref-picker-meta { margin-top:0.25rem; display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; font-size:0.78rem; color:#6b7280; }
		.pagination-bar { display:flex; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; gap:0.5rem; }
		@media (max-width: 768px) {
			.edit-grid { grid-template-columns: 1fr; }
			table { font-size: 0.78rem; }
		}
	</style>
</head>

<body>
	<main class="container">
		<nav>
			<h1>Pending Approval <span class="muted" style="font-size:1rem;">({{ $pendingTotal }})</span></h1>
			<div class="toolbar">
				<a href="{{ route('bids.index') }}" role="button" class="secondary" style="padding:0.5rem 1rem; font-size:0.9rem;">Back to Bids</a>
				@if ($pendingTotal > 0)
					<form method="POST" action="{{ route('pending.approveAll') }}" onsubmit="return confirm('Approve ALL pending bids and publish them to the live table?')" style="margin:0;">
						@csrf
						<button type="submit" style="padding:0.5rem 1rem; font-size:0.9rem; background:#16a34a; border-color:#16a34a;">Approve all</button>
					</form>
					<form method="POST" action="{{ route('pending.rejectAll') }}" onsubmit="return confirm('Reject (delete) ALL pending bids? This cannot be undone.')" style="margin:0;">
						@csrf
						<button type="submit" class="outline contrast" style="padding:0.5rem 1rem; font-size:0.9rem;">Reject all</button>
					</form>
				@endif
			</div>
		</nav>

		@if (session('success'))
			<div class="alert success">{{ session('success') }}</div>
		@endif
		@if ($errors->any())
			<div class="alert error">{{ $errors->first() }}</div>
		@endif

		<section class="card">
			<form method="GET" action="{{ route('pending.index') }}" style="display:flex; gap:0.5rem; margin-bottom:1rem; flex-wrap:wrap;">
				<input type="search" name="search" value="{{ $search }}" placeholder="Search title, URL, source, NAICS…" style="max-width:340px; margin:0;">
				<button type="submit" class="secondary" style="margin:0;">Search</button>
				@if ($search !== '')
					<a href="{{ route('pending.index') }}" role="button" class="outline" style="margin:0;">Clear</a>
				@endif
			</form>

			@if ($pending->total() === 0)
				<p class="muted" style="text-align:center; padding:2rem 0;">
					No bids are waiting for approval. Newly scraped bids will appear here for review.
				</p>
			@else
				<p class="muted" style="font-size:0.85rem; margin-bottom:0.75rem;">
					Showing {{ $pending->firstItem() }}-{{ $pending->lastItem() }} of {{ $pending->total() }} pending bid(s).
					Click a title to edit.
				</p>
				<div style="overflow-x:auto;">
					<table>
						<thead>
							<tr>
								<th>Title</th>
								<th>Entity</th>
								<th>NAICS Code</th>
								<th>Scraped</th>
								<th style="width:5.5rem;" aria-label="Actions"></th>
							</tr>
						</thead>
						<tbody>
							@foreach ($pending as $idx => $row)
								@php $eid = (int) ($row->ENTITYID ?? 0); @endphp
								<tr>
									<td class="title-cell">
										<div class="title-cell-inner">
											<button type="button" class="title-edit-trigger" onclick="openEdit({{ $idx }})" title="Click to edit">{{ $row->TITLE ?: 'Untitled bid' }}</button>
											@if ($row->URL)
												<a href="{{ $row->URL }}" class="title-external-link" target="_blank" rel="noopener" title="Open listing URL" onclick="event.stopPropagation();">
													<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
												</a>
											@endif
										</div>
									</td>
									<td>
										@if ($eid > 0)
											<span class="entity-pill has" title="{{ $entityLabels[$eid] ?? ('Entity #' . $eid) }}">{{ \Illuminate\Support\Str::limit($entityLabels[$eid] ?? ('#' . $eid), 28) }}</span>
										@else
											<span class="entity-pill none">No entity</span>
										@endif
									</td>
									<td class="naics-cell muted">{{ $row->NAICSCODE ?: '—' }}</td>
									<td class="muted" style="white-space:nowrap;">{{ $row->created_at ? $row->created_at->format('n/j') : '—' }}</td>
									<td>
										<div class="row-actions">
											<form method="POST" action="{{ route('pending.approve', $row) }}" style="margin:0;">
												@csrf
												<input type="hidden" name="search" value="{{ $search }}">
												<input type="hidden" name="page" value="{{ $pending->currentPage() }}">
												<button type="submit" class="icon-action icon-action--approve" title="Approve — publish to live table" aria-label="Approve">
													<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
												</button>
											</form>
											<form method="POST" action="{{ route('pending.reject', $row) }}" style="margin:0;" onsubmit="return confirm('Reject (delete) this pending bid?')">
												@csrf
												@method('DELETE')
												<input type="hidden" name="search" value="{{ $search }}">
												<input type="hidden" name="page" value="{{ $pending->currentPage() }}">
												<button type="submit" class="icon-action icon-action--reject" title="Reject — discard this bid" aria-label="Reject">
													<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
												</button>
											</form>
										</div>
									</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				</div>

				<div class="pagination-bar">
					<div class="muted">Page {{ $pending->currentPage() }} of {{ $pending->lastPage() }}</div>
					<div>{{ $pending->links() }}</div>
				</div>
			@endif
		</section>
	</main>

	<!-- Edit Modal -->
	<dialog id="editModal">
		<article>
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
				<h3 style="margin:0;">Edit pending bid</h3>
				<button type="button" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; padding:0; line-height:1;" onclick="document.getElementById('editModal').close()">&times;</button>
			</div>
			<form id="editForm" method="POST">
				@csrf
				<input type="hidden" name="_method" id="edit_method" value="PUT">
				<input type="hidden" name="search" value="{{ $search }}">
				<input type="hidden" name="page" value="{{ $pending->currentPage() }}">

				<div class="edit-grid">
					<div class="full">
						<label for="edit_title">Title</label>
						<input type="text" id="edit_title" name="TITLE" required maxlength="255">
					</div>
					<div class="full ref-picker" id="entityPicker">
						<label for="edit_entity_search">Entity <span id="editEntityHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="edit_entity_id" name="ENTITYID">
						<div class="ref-picker-inner">
							<input type="search" id="edit_entity_search" autocomplete="off" autocorrect="off" spellcheck="false"
								placeholder="Search entity master list by name, email, or ID…"
								aria-autocomplete="list" aria-expanded="false" aria-controls="edit_entity_results">
							<ul id="edit_entity_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="edit_entity_clear">Clear entity</button>
							<span>Pick a row to set ENTITYID.</span>
						</div>
					</div>
					<div class="full ref-picker" id="statePicker">
						<label for="edit_state_search">State <span id="editStateHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="edit_state_id" name="STATEID">
						<div class="ref-picker-inner">
							<input type="search" id="edit_state_search" autocomplete="off" autocorrect="off" spellcheck="false"
								placeholder="Search states by name or abbreviation (e.g. CO)…"
								aria-autocomplete="list" aria-expanded="false" aria-controls="edit_state_results">
							<ul id="edit_state_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="edit_state_clear">Clear state</button>
							<span>Pick a row to set STATEID.</span>
						</div>
					</div>
					<div>
						<label for="edit_enddate">End date</label>
						<input type="date" id="edit_enddate" name="ENDDATE">
					</div>
					<div>
						<label for="edit_naics">NAICS code</label>
						<input type="text" id="edit_naics" name="NAICSCODE" maxlength="255">
					</div>
					<div>
						<label for="edit_email">Contact email</label>
						<input type="email" id="edit_email" name="EMAIL" maxlength="255">
					</div>
					<div>
						<label for="edit_userid">Assign to</label>
						<select id="edit_userid" name="USERID">
							<option value="">— None —</option>
							@foreach ($manilaDirectoryUsers ?? [] as $dirUser)
								<option value="{{ $dirUser['id'] }}">{{ $dirUser['label'] }}</option>
							@endforeach
						</select>
					</div>
					<div class="full">
						<label for="edit_url">Listing URL</label>
						<input type="text" id="edit_url" name="URL" maxlength="500">
					</div>
					<div class="full">
						<label for="edit_description">Description</label>
						<textarea id="edit_description" name="DESCRIPTION" rows="5"></textarea>
					</div>
				</div>

				<footer style="display:flex; justify-content:space-between; gap:0.75rem; margin-top:1.5rem;">
					<button type="button" class="secondary outline" onclick="document.getElementById('editModal').close()">Cancel</button>
					<div style="display:flex; gap:0.5rem;">
						<button type="submit" class="secondary" onclick="prepareSubmit('update')">Save changes</button>
						<button type="submit" id="saveApproveBtn" style="background:#16a34a; border-color:#16a34a;" onclick="prepareSubmit('approve')">Save &amp; approve</button>
					</div>
				</footer>
			</form>
		</article>
	</dialog>

	<script>
		@php
			$entitySearchUrl = route('bids.reference.entities');
			$stateSearchUrl = route('bids.reference.states');
			$updateUrlTpl = route('pending.update', ['pendingBid' => '__ID__']);
			$approveUrlTpl = route('pending.approve', ['pendingBid' => '__ID__']);
			$pendingRows = $pending->getCollection()->map(function ($r) {
				return [
					'id' => $r->id,
					'TITLE' => $r->TITLE,
					'DESCRIPTION' => $r->DESCRIPTION,
					'EMAIL' => $r->EMAIL,
					'URL' => $r->URL,
					'ENDDATE' => $r->ENDDATE ? \Illuminate\Support\Carbon::parse($r->ENDDATE)->format('Y-m-d') : '',
					'NAICSCODE' => $r->NAICSCODE,
					'ENTITYID' => $r->ENTITYID,
					'STATEID' => $r->STATEID,
					'USERID' => $r->USERID,
				];
			})->values();
		@endphp
		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
		const entitySearchUrl = @json($entitySearchUrl);
		const stateSearchUrl = @json($stateSearchUrl);
		const updateUrlTpl = @json($updateUrlTpl);
		const approveUrlTpl = @json($approveUrlTpl);
		const pendingData = @json($pendingRows);

		let currentUpdateUrl = '';
		let currentApproveUrl = '';

		const entityPicker = initRefPicker({
			hidden: document.getElementById('edit_entity_id'),
			search: document.getElementById('edit_entity_search'),
			results: document.getElementById('edit_entity_results'),
			clearBtn: document.getElementById('edit_entity_clear'),
			hint: document.getElementById('editEntityHint'),
			searchUrl: entitySearchUrl,
			fallbackPrefix: 'Entity #',
			searchLimit: 40,
			minChars: 0,
		});

		const statePicker = initRefPicker({
			hidden: document.getElementById('edit_state_id'),
			search: document.getElementById('edit_state_search'),
			results: document.getElementById('edit_state_results'),
			clearBtn: document.getElementById('edit_state_clear'),
			hint: document.getElementById('editStateHint'),
			searchUrl: stateSearchUrl,
			fallbackPrefix: 'State #',
			searchLimit: 60,
			minChars: 0,
		});

		function initRefPicker(cfg) {
			let reqSeq = 0;
			let selectedLabel = '';
			let highlightIdx = -1;

			function refreshHint() {
				if (!cfg.hint) return;
				const id = (cfg.hidden?.value || '').trim();
				cfg.hint.textContent = (id !== '' && id !== '0') ? '· #' + id : '· none';
			}

			function hideResults() {
				if (!cfg.results || !cfg.search) return;
				cfg.results.hidden = true;
				cfg.results.innerHTML = '';
				cfg.search.setAttribute('aria-expanded', 'false');
				highlightIdx = -1;
			}

			function highlightRows(rows, activeIdx) {
				rows.forEach((li, i) => {
					const on = activeIdx >= 0 && i === activeIdx;
					li.classList.toggle('ref-res-active', on);
					li.setAttribute('aria-selected', on ? 'true' : 'false');
				});
			}

			function applyChoice(id, label) {
				if (!cfg.hidden || !cfg.search) return;
				cfg.hidden.value = String(id);
				cfg.search.value = label;
				selectedLabel = label;
				refreshHint();
				hideResults();
			}

			async function setFromId(raw) {
				if (!cfg.hidden || !cfg.search) return;
				selectedLabel = '';
				hideResults();
				const idStr = raw != null && String(raw) !== '' && String(raw) !== '0' ? String(raw) : '';
				cfg.hidden.value = idStr;
				if (!idStr) {
					cfg.search.value = '';
					refreshHint();
					return;
				}
				refreshHint();
				try {
					const r = await fetch(cfg.searchUrl + '?id=' + encodeURIComponent(idStr), {
						headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
					});
					const data = await r.json();
					cfg.search.value = (data.resolved && data.resolved.label) ? data.resolved.label : (cfg.fallbackPrefix + idStr);
				} catch (e) {
					cfg.search.value = cfg.fallbackPrefix + idStr;
				}
				selectedLabel = cfg.search.value;
			}

			async function runSearch(query) {
				if (!cfg.results || !cfg.search) return;
				reqSeq += 1;
				const seq = reqSeq;
				let data;
				try {
					const r = await fetch(cfg.searchUrl + '?q=' + encodeURIComponent(query) + '&limit=' + cfg.searchLimit, {
						headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
					});
					data = await r.json();
				} catch (e) {
					return;
				}
				if (seq !== reqSeq || cfg.search.value.trim() !== query) return;

				cfg.results.innerHTML = '';
				const items = Array.isArray(data.results) ? data.results : [];
				if (!items.length) {
					const li = document.createElement('li');
					li.className = 'muted';
					li.textContent = 'No matches.';
					cfg.results.appendChild(li);
				} else {
					items.forEach(function (row) {
						const li = document.createElement('li');
						li.setAttribute('role', 'option');
						li.dataset.refId = String(row.id);
						const lab = row.label || (cfg.fallbackPrefix + row.id);
						li.dataset.refLabel = lab;
						li.textContent = lab;
						cfg.results.appendChild(li);
					});
				}
				cfg.results.hidden = false;
				cfg.search.setAttribute('aria-expanded', 'true');
				highlightRows([...cfg.results.children].filter((li) => li.dataset.refId), -1);
			}

			function pickableRows() {
				return [...(cfg.results?.children || [])].filter((li) => li.dataset.refId);
			}

			if (cfg.search && cfg.results) {
				let timer = null;

				cfg.search.addEventListener('input', function () {
					highlightIdx = -1;
					if (selectedLabel !== '' && cfg.search.value !== selectedLabel) {
						cfg.hidden.value = '';
						refreshHint();
					}
					clearTimeout(timer);
					const q = cfg.search.value.trim();
					if (q.length < cfg.minChars) {
						hideResults();
						return;
					}
					timer = setTimeout(() => runSearch(q), 220);
				});

				cfg.search.addEventListener('focus', function () {
					const q = cfg.search.value.trim();
					if (q.length >= cfg.minChars && cfg.results.hidden) {
						runSearch(q);
					}
				});

				cfg.search.addEventListener('keydown', function (e) {
					const picks = pickableRows();
					if (!picks.length || cfg.results.hidden) return;
					if (e.key === 'ArrowDown') {
						e.preventDefault();
						highlightIdx = highlightIdx < 0 || highlightIdx >= picks.length - 1 ? 0 : highlightIdx + 1;
						highlightRows(picks, highlightIdx);
					} else if (e.key === 'ArrowUp') {
						e.preventDefault();
						highlightIdx = highlightIdx <= 0 ? picks.length - 1 : highlightIdx - 1;
						highlightRows(picks, highlightIdx);
					} else if (e.key === 'Enter' && highlightIdx >= 0 && picks[highlightIdx]) {
						e.preventDefault();
						const li = picks[highlightIdx];
						applyChoice(li.dataset.refId, li.dataset.refLabel || '');
					} else if (e.key === 'Escape') {
						hideResults();
					}
				});

				cfg.search.addEventListener('blur', function () {
					setTimeout(hideResults, 220);
				});

				cfg.results.addEventListener('mousedown', function (ev) {
					if (ev.target.closest('li[data-ref-id]')) ev.preventDefault();
				});

				cfg.results.addEventListener('click', function (ev) {
					const li = ev.target.closest('li[data-ref-id]');
					if (!li) return;
					applyChoice(li.dataset.refId, li.dataset.refLabel || '');
				});
			}

			cfg.clearBtn?.addEventListener('click', function () {
				if (cfg.hidden) cfg.hidden.value = '';
				if (cfg.search) cfg.search.value = '';
				selectedLabel = '';
				refreshHint();
				hideResults();
			});

			return { setFromId, hideResults };
		}

		function prepareSubmit(target) {
			const form = document.getElementById('editForm');
			if (target === 'approve') {
				form.action = currentApproveUrl;
				document.getElementById('edit_method').value = 'POST';
			} else {
				form.action = currentUpdateUrl;
				document.getElementById('edit_method').value = 'PUT';
			}
		}

		function openEdit(idx) {
			const bid = pendingData[idx];
			if (!bid) return;
			const form = document.getElementById('editForm');
			currentUpdateUrl = updateUrlTpl.replace('__ID__', bid.id);
			currentApproveUrl = approveUrlTpl.replace('__ID__', bid.id);
			form.action = currentUpdateUrl;
			document.getElementById('edit_method').value = 'PUT';

			document.getElementById('edit_title').value = bid.TITLE || '';
			document.getElementById('edit_description').value = bid.DESCRIPTION || '';
			document.getElementById('edit_email').value = bid.EMAIL || '';
			document.getElementById('edit_url').value = bid.URL || '';
			document.getElementById('edit_enddate').value = bid.ENDDATE || '';
			document.getElementById('edit_naics').value = bid.NAICSCODE || '';
			const userSel = document.getElementById('edit_userid');
			userSel.value = bid.USERID != null && String(bid.USERID) !== '0' ? String(bid.USERID) : '';

			entityPicker.setFromId(bid.ENTITYID);
			statePicker.setFromId(bid.STATEID);
			document.getElementById('editModal').showModal();
		}
	</script>
</body>

</html>
