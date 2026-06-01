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
		.row-actions { display:flex; gap:0.35rem; flex-wrap:nowrap; }
		.row-actions button { padding:0.2rem 0.55rem; font-size:0.78rem; margin:0; min-width:auto; }
		.btn-approve { background:#16a34a; border-color:#16a34a; }
		.btn-edit { background:#fff; color:#2563eb; border:1px solid #bfdbfe; }
		.btn-reject { background:#fff; color:#b91c1c; border:1px solid #fecaca; }
		.title-cell { max-width: 380px; overflow-wrap:anywhere; }
		.title-cell a { color:#2563eb; }
		.toolbar { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; }
		dialog { max-width: 640px; width: 92%; border:none; border-radius:12px; padding:0; box-shadow:0 8px 30px rgba(0,0,0,0.15); }
		dialog article { margin:0; padding:1.75rem; }
		.edit-grid { display:grid; grid-template-columns: 1fr 1fr; gap:0.9rem; }
		.edit-grid .full { grid-column: 1 / -1; }
		.edit-grid label { font-size:0.82rem; font-weight:600; color:#374151; margin-bottom:0.2rem; display:block; }
		.entity-box { position:relative; }
		#editEntityResults {
			position:absolute; left:0; right:0; top:100%; z-index:30; margin:0.2rem 0 0; padding:0; list-style:none;
			background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-height:240px; overflow-y:auto;
			box-shadow:0 6px 18px rgba(0,0,0,0.12);
		}
		#editEntityResults li { padding:0.5rem 0.7rem; cursor:pointer; font-size:0.85rem; }
		#editEntityResults li.entity-res-active, #editEntityResults li:hover { background:#eff6ff; }
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
					Showing {{ $pending->firstItem() }}-{{ $pending->lastItem() }} of {{ $pending->total() }} pending bid(s)
				</p>
				<div style="overflow-x:auto;">
					<table>
						<thead>
							<tr>
								<th>Title</th>
								<th>Entity</th>
								<th>End Date</th>
								<th>Source</th>
								<th>Scraped</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							@foreach ($pending as $idx => $row)
								@php $eid = (int) ($row->ENTITYID ?? 0); @endphp
								<tr>
									<td class="title-cell">
										@if ($row->URL)
											<a href="{{ $row->URL }}" target="_blank" rel="noopener">{{ $row->TITLE ?: 'Untitled bid' }}</a>
										@else
											{{ $row->TITLE ?: 'Untitled bid' }}
										@endif
									</td>
									<td>
										@if ($eid > 0)
											<span class="entity-pill has" title="{{ $entityLabels[$eid] ?? ('Entity #' . $eid) }}">{{ \Illuminate\Support\Str::limit($entityLabels[$eid] ?? ('#' . $eid), 28) }}</span>
										@else
											<span class="entity-pill none">No entity</span>
										@endif
									</td>
									<td style="white-space:nowrap;">{{ $row->ENDDATE ? \Illuminate\Support\Carbon::parse($row->ENDDATE)->format('M d, Y') : '—' }}</td>
									<td class="muted" style="max-width:200px; overflow-wrap:anywhere;">{{ $row->bid_url_name ?: ($row->source_listing_url ? parse_url($row->source_listing_url, PHP_URL_HOST) : '—') }}</td>
									<td class="muted" style="white-space:nowrap;">{{ $row->created_at ? $row->created_at->format('M d, Y h:i A') : '—' }}</td>
									<td>
										<div class="row-actions">
											<button type="button" class="btn-edit" onclick="openEdit({{ $idx }})" title="Edit before approving">Edit</button>
											<form method="POST" action="{{ route('pending.approve', $row) }}" style="margin:0;">
												@csrf
												<input type="hidden" name="search" value="{{ $search }}">
												<input type="hidden" name="page" value="{{ $pending->currentPage() }}">
												<button type="submit" class="btn-approve" title="Publish to live table">Approve</button>
											</form>
											<form method="POST" action="{{ route('pending.reject', $row) }}" style="margin:0;" onsubmit="return confirm('Reject (delete) this pending bid?')">
												@csrf
												@method('DELETE')
												<input type="hidden" name="search" value="{{ $search }}">
												<input type="hidden" name="page" value="{{ $pending->currentPage() }}">
												<button type="submit" class="btn-reject" title="Discard this bid">Reject</button>
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
					<div class="full entity-box">
						<label for="editEntitySearch">Entity <span id="editEntityHint" class="muted" style="font-weight:400;"></span></label>
						<input type="text" id="editEntitySearch" autocomplete="off" placeholder="Search entities by name or email…" aria-expanded="false">
						<input type="hidden" id="edit_entity_id" name="ENTITYID">
						<ul id="editEntityResults" role="listbox" hidden></ul>
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
		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
		const entitySearchUrl = @json(route('bids.reference.entities'));
		const updateUrlTpl = @json(route('pending.update', ['pendingBid' => '__ID__']));
		const approveUrlTpl = @json(route('pending.approve', ['pendingBid' => '__ID__']));
		const pendingData = @json($pending->getCollection()->map(function ($r) {
			return [
				'id' => $r->id,
				'TITLE' => $r->TITLE,
				'DESCRIPTION' => $r->DESCRIPTION,
				'EMAIL' => $r->EMAIL,
				'URL' => $r->URL,
				'ENDDATE' => $r->ENDDATE ? \Illuminate\Support\Carbon::parse($r->ENDDATE)->format('Y-m-d') : '',
				'NAICSCODE' => $r->NAICSCODE,
				'ENTITYID' => $r->ENTITYID,
				'USERID' => $r->USERID,
			];
		})->values());

		let entityReq = 0;
		let entitySelectedLabel = '';
		let currentUpdateUrl = '';
		let currentApproveUrl = '';

		function escHtml(str) { const d = document.createElement('div'); d.textContent = str == null ? '' : str; return d.innerHTML; }

		// Toggle action + method so the two submit buttons hit the right route.
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

			setEntityFromId(bid.ENTITYID);
			document.getElementById('editModal').showModal();
		}

		function refreshEntityHint() {
			const hint = document.getElementById('editEntityHint');
			const id = document.getElementById('edit_entity_id').value.trim();
			hint.textContent = (id !== '' && id !== '0') ? '· #' + id : '· none';
		}

		function hideEntityResults() {
			const ul = document.getElementById('editEntityResults');
			ul.hidden = true; ul.innerHTML = '';
			document.getElementById('editEntitySearch').setAttribute('aria-expanded', 'false');
		}

		async function setEntityFromId(raw) {
			const hidden = document.getElementById('edit_entity_id');
			const search = document.getElementById('editEntitySearch');
			entitySelectedLabel = '';
			hideEntityResults();
			const idStr = raw != null && String(raw) !== '' && String(raw) !== '0' ? String(raw) : '';
			hidden.value = idStr;
			if (!idStr) { search.value = ''; refreshEntityHint(); return; }
			refreshEntityHint();
			try {
				const r = await fetch(entitySearchUrl + '?id=' + encodeURIComponent(idStr), {
					headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
				});
				const data = await r.json();
				search.value = (data.resolved && data.resolved.label) ? data.resolved.label : ('Entity #' + idStr);
			} catch (e) { search.value = 'Entity #' + idStr; }
			entitySelectedLabel = search.value;
		}

		async function runEntitySearch(query) {
			const ul = document.getElementById('editEntityResults');
			const search = document.getElementById('editEntitySearch');
			entityReq += 1;
			const seq = entityReq;
			let data;
			try {
				const r = await fetch(entitySearchUrl + '?q=' + encodeURIComponent(query) + '&limit=40', {
					headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
				});
				data = await r.json();
			} catch (e) { return; }
			if (seq !== entityReq || search.value.trim() !== query) return;
			ul.innerHTML = '';
			const items = Array.isArray(data.results) ? data.results : [];
			if (!items.length) {
				const li = document.createElement('li'); li.className = 'muted'; li.textContent = 'No matches.'; ul.appendChild(li);
			} else {
				items.forEach(function (row) {
					const li = document.createElement('li');
					li.setAttribute('role', 'option');
					li.dataset.entityId = String(row.id);
					const lab = row.label || ('#' + row.id);
					li.dataset.entityLabel = lab;
					li.textContent = lab;
					ul.appendChild(li);
				});
			}
			ul.hidden = false;
			search.setAttribute('aria-expanded', 'true');
		}

		(function bindEntityPicker() {
			const hidden = document.getElementById('edit_entity_id');
			const search = document.getElementById('editEntitySearch');
			const ul = document.getElementById('editEntityResults');
			let timer = null;

			search.addEventListener('input', function () {
				if (entitySelectedLabel !== '' && search.value !== entitySelectedLabel) {
					hidden.value = '';
					refreshEntityHint();
				}
				const q = search.value.trim();
				clearTimeout(timer);
				if (q.length < 2) { hideEntityResults(); return; }
				timer = setTimeout(() => runEntitySearch(q), 200);
			});

			ul.addEventListener('click', function (e) {
				const li = e.target.closest('li[data-entity-id]');
				if (!li) return;
				hidden.value = li.dataset.entityId;
				search.value = li.dataset.entityLabel;
				entitySelectedLabel = li.dataset.entityLabel;
				hideEntityResults();
				refreshEntityHint();
			});

			document.addEventListener('click', function (e) {
				if (!e.target.closest('.entity-box')) hideEntityResults();
			});
		})();
	</script>
</body>

</html>
