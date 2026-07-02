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
		nav .nav-actions {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			flex-wrap: wrap;
		}
		nav .nav-actions form {
			margin: 0;
			display: flex;
			align-items: center;
		}
		nav .nav-actions a[role="button"],
		nav .nav-actions button {
			margin: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 0.35rem;
			min-height: 2.5rem;
			padding: 0.5rem 1rem;
			font-size: 0.9rem;
			line-height: 1.2;
			box-sizing: border-box;
			white-space: nowrap;
		}
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
		/* Pico constrains dialog > article width and centers it — override for full-viewport modal */
		dialog#editModal {
			width: calc(100vw - 1.5rem) !important;
			max-width: calc(100vw - 1.5rem) !important;
			height: calc(100dvh - 1.5rem) !important;
			max-height: calc(100dvh - 1.5rem) !important;
			margin: auto;
			border: none;
			border-radius: 10px;
			padding: 0 !important;
			box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
			overflow: hidden;
		}
		dialog#editModal[open] {
			display: flex !important;
			flex-direction: column;
			align-items: stretch !important;
			justify-content: stretch;
		}
		dialog#editModal::backdrop { background: rgba(15, 23, 42, 0.45); }
		dialog#editModal .edit-modal-shell {
			width: 100% !important;
			max-width: none !important;
			margin: 0 !important;
			padding: 2rem 2.25rem;
			flex: 1 1 auto;
			min-height: 0;
			max-height: calc(100dvh - 1.5rem);
			overflow-y: auto;
			overflow-x: hidden;
			box-sizing: border-box;
			background: var(--pico-background-color, #fff);
		}
		dialog#editModal input[type="text"],
		dialog#editModal input[type="search"],
		dialog#editModal input[type="email"],
		dialog#editModal input[type="date"],
		dialog#editModal textarea,
		dialog#editModal select {
			width: 100%;
			max-width: none !important;
		}
		dialog#editModal footer {
			margin-top: 1.5rem;
		}
		.edit-modal-layout {
			display: grid;
			grid-template-columns: minmax(0, 1fr) minmax(280px, 380px);
			gap: 2rem;
			align-items: start;
		}
		.edit-modal-main { min-width: 0; }
		.similar-panel {
			background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
			padding:1rem 1.1rem; position:sticky; top:0;
			max-height: calc(100dvh - 8rem);
			overflow-y:auto;
		}
		.similar-panel h4 { margin:0 0 0.35rem; font-size:0.88rem; font-weight:600; color:#1e293b; }
		.similar-panel .similar-meta { font-size:0.72rem; color:#64748b; margin-bottom:0.65rem; line-height:1.35; }
		.similar-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.55rem; }
		.similar-item {
			background:#fff; border:1px solid #e5e7eb; border-radius:8px;
			padding:0.55rem 0.6rem; font-size:0.78rem; line-height:1.35;
		}
		.similar-item-title { font-weight:600; color:#1e293b; margin:0 0 0.2rem; overflow-wrap:anywhere; }
		.similar-detail-link {
			background:none; border:none; padding:0; margin:0; min-width:0;
			color:#2563eb; font:inherit; font-weight:600; cursor:pointer;
			text-align:left; text-decoration:underline; text-underline-offset:2px;
		}
		.similar-detail-link:hover { color:#1d4ed8; }
		dialog#similarDetailModal {
			max-width: 820px; width: min(92vw, 820px); border: none; border-radius: 12px;
			padding: 0; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
		}
		dialog#similarDetailModal[open] {
			display: flex !important;
			flex-direction: column;
			align-items: stretch !important;
		}
		dialog#similarDetailModal .similar-detail-shell {
			width: 100% !important;
			max-width: none !important;
			margin: 0 !important;
			padding: 2rem;
			max-height: 90dvh;
			overflow-y: auto;
			box-sizing: border-box;
			background: var(--pico-background-color, #fff);
		}
		.similar-detail-meta {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 1rem;
			margin-bottom: 1.25rem;
		}
		.similar-detail-meta .meta-card {
			background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.85rem;
		}
		.similar-detail-meta .meta-card strong {
			display: block; color: #374151; font-size: 0.85rem; margin-bottom: 0.25rem;
		}
		.similar-detail-meta .meta-card span { color: #111827; word-break: break-word; }
		#similar_detail_description {
			background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
			padding: 1rem; max-height: 400px; overflow-y: auto; font-size: 0.9rem; line-height: 1.6;
		}
		.similar-item-dl { margin:0; display:grid; grid-template-columns:auto 1fr; gap:0.1rem 0.45rem; color:#64748b; }
		.similar-item-dl dt { font-weight:600; }
		.similar-item-dl dd { margin:0; overflow-wrap:anywhere; }
		.similar-badge {
			display:inline-block; font-size:0.65rem; font-weight:700; text-transform:uppercase;
			letter-spacing:0.03em; padding:0.1rem 0.35rem; border-radius:4px; margin-bottom:0.25rem;
		}
		.similar-badge.live { background:#eff6ff; color:#1d4ed8; }
		.similar-badge.pending { background:#fff7ed; color:#c2410c; }
		.similar-empty { font-size:0.78rem; color:#94a3b8; margin:0; }
		.similar-loading { font-size:0.78rem; color:#64748b; margin:0; }
		@media (max-width: 1024px) {
			.edit-modal-layout { grid-template-columns: 1fr; }
			.similar-panel { position: static; max-height: none; }
		}
		.edit-grid { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:1.1rem 1.25rem; }
		@media (max-width: 1200px) {
			.edit-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		}
		.edit-grid .full { grid-column: 1 / -1; }
		.edit-grid label { font-size:0.82rem; font-weight:600; color:#374151; margin-bottom:0.2rem; display:block; }
		.ref-picker { position:relative; }
		.ref-picker-inner input[type="search"] { margin-bottom:0.15rem; }
		.ref-picker-results {
			position:absolute; left:0; right:0; top:100%; z-index:50; margin:0.2rem 0 0; padding:0; list-style:none;
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
			<div class="nav-actions">
				<a href="{{ route('bids.index') }}" role="button" class="secondary">Back to Bids</a>
				@if ($pendingTotal > 0)
					<form method="POST" action="{{ route('pending.approveAll') }}" onsubmit="return confirm('Approve ALL pending bids and publish them to the live table?')">
						@csrf
						<button type="submit" style="background:#16a34a; border-color:#16a34a;">Approve all</button>
					</form>
					<form method="POST" action="{{ route('pending.rejectAll') }}" onsubmit="return confirm('Reject (delete) ALL pending bids? This cannot be undone.')">
						@csrf
						<button type="submit" class="outline contrast">Reject all</button>
					</form>
				@endif
			</div>
		</nav>

		@if (session('success'))
			<div class="alert success">{{ session('success') }}</div>
		@endif
		@if (session('error'))
			<div class="alert error">{{ session('error') }}</div>
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
		<div class="edit-modal-shell">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
				<h3 style="margin:0;">Edit pending bid</h3>
				<button type="button" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; padding:0; line-height:1;" onclick="document.getElementById('editModal').close()">&times;</button>
			</div>
			<div class="edit-modal-layout">
				<form id="editForm" method="POST" class="edit-modal-main">
				@csrf
				<input type="hidden" name="_method" id="edit_method" value="PUT">
				<input type="hidden" name="edit_modal" value="1">
				<input type="hidden" id="edit_approve_action" name="approve_action" value="">
				<input type="hidden" name="search" value="{{ $search }}">
				<input type="hidden" name="page" value="{{ $pending->currentPage() }}">

				<div class="edit-grid">
					<div class="full">
						<label for="edit_title">Title</label>
						<input type="text" id="edit_title" name="TITLE" required maxlength="255">
					</div>
					<div class="full ref-picker" id="bidUrlPicker">
						<label for="edit_bid_url_search">Bid URL <span class="muted" style="font-weight:400;">(BIDURL.ID)</span> <span id="editBidUrlHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="edit_bid_url_id" name="BID_URL_ID">
						<div class="ref-picker-inner">
							<input type="search" id="edit_bid_url_search" autocomplete="off" autocorrect="off" spellcheck="false"
								placeholder="Search configured bid URLs by name or URL…"
								aria-autocomplete="list" aria-expanded="false" aria-controls="edit_bid_url_results">
							<ul id="edit_bid_url_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="edit_bid_url_clear">Clear bid URL</button>
							<span>Search ODS BIDURL by name, URL, or ID; sets <code>BID_URL_ID</code> on save.</span>
						</div>
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
					<div class="full ref-picker" id="categoryPicker">
						<label for="edit_category_search">Category <span id="editCategoryHint" class="muted" style="font-weight:400;"></span></label>
						<input type="hidden" id="edit_category_id" name="CATEGORYID">
						<div class="ref-picker-inner">
							<input type="search" id="edit_category_search" autocomplete="off" autocorrect="off" spellcheck="false"
								placeholder="Search categories by name…"
								aria-autocomplete="list" aria-expanded="false" aria-controls="edit_category_results">
							<ul id="edit_category_results" class="ref-picker-results" role="listbox" hidden></ul>
						</div>
						<div class="ref-picker-meta">
							<button type="button" class="ref-picker-clear" id="edit_category_clear">Clear category</button>
							<span>Pick a row to set CATEGORYID.</span>
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
						<button type="button" id="saveChangesBtn" class="secondary">Save changes</button>
						<button type="button" id="saveApproveBtn" style="background:#16a34a; border-color:#16a34a;">Save &amp; approve</button>
					</div>
				</footer>
			</form>

				<aside class="similar-panel" aria-labelledby="similarPanelHeading">
					<h4 id="similarPanelHeading">Similar bids</h4>
					<p class="similar-meta" id="similarMatchLabel">Last 5 by entity, then email, then URL.</p>
					<p class="similar-loading" id="similarLoading" hidden>Loading…</p>
					<ul class="similar-list" id="similarList" hidden></ul>
					<p class="similar-empty" id="similarEmpty" hidden>No similar bids found.</p>
				</aside>
			</div>
		</div>
	</dialog>

	<!-- Similar bid detail (opens over edit modal) -->
	<dialog id="similarDetailModal">
		<div class="similar-detail-shell">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem;">
				<div>
					<span id="similar_detail_badge" class="similar-badge live" style="margin-bottom:0.35rem;">Live</span>
					<h3 id="similar_detail_title" style="margin:0; font-size:1.25rem; font-weight:600; color:#1f2937;"></h3>
				</div>
				<button type="button" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; padding:0; line-height:1;" onclick="document.getElementById('similarDetailModal').close()">&times;</button>
			</div>

			<div class="similar-detail-meta">
				<div class="meta-card">
					<strong>End date</strong>
					<span id="similar_detail_enddate"></span>
				</div>
				<div class="meta-card">
					<strong>NAICS code</strong>
					<span id="similar_detail_naics"></span>
				</div>
				<div class="meta-card">
					<strong>Scraped</strong>
					<span id="similar_detail_created"></span>
				</div>
				<div class="meta-card">
					<strong>Entity</strong>
					<span id="similar_detail_entity"></span>
				</div>
				<div class="meta-card">
					<strong>Contact email</strong>
					<span id="similar_detail_email"></span>
				</div>
			</div>

			<div class="meta-card" style="margin-bottom:1.25rem;">
				<strong>URL</strong>
				<a id="similar_detail_url" href="#" target="_blank" rel="noopener noreferrer" style="word-break:break-all;"></a>
			</div>

			<div>
				<strong style="display:block; color:#374151; font-size:0.95rem; margin-bottom:0.5rem;">Details</strong>
				<div id="similar_detail_description"></div>
			</div>

			<footer style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
				<button type="button" class="secondary" onclick="document.getElementById('similarDetailModal').close()">Close</button>
			</footer>
		</div>
	</dialog>

	<script>
		@php
			$entitySearchUrl = route('bids.reference.entities');
			$stateSearchUrl = route('bids.reference.states');
			$categorySearchUrl = route('bids.reference.categories');
			$bidUrlSearchUrl = route('bids.reference.bidUrls');
			$similarUrl = route('pending.similar');
			$liveDetailUrlTpl = route('bids.json', ['bid' => '__ID__']);
			$pendingDetailUrlTpl = route('pending.json', ['pendingBid' => '__ID__']);
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
					'CATEGORYID' => $r->CATEGORYID,
					'BID_URL_ID' => $r->BID_URL_ID,
					'USERID' => $r->USERID,
				];
			})->values();
		@endphp
		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
		const entitySearchUrl = @json($entitySearchUrl);
		const stateSearchUrl = @json($stateSearchUrl);
		const categorySearchUrl = @json($categorySearchUrl);
		const bidUrlSearchUrl = @json($bidUrlSearchUrl);
		const similarUrl = @json($similarUrl);
		const liveDetailUrlTpl = @json($liveDetailUrlTpl);
		const pendingDetailUrlTpl = @json($pendingDetailUrlTpl);
		const updateUrlTpl = @json($updateUrlTpl);
		const approveUrlTpl = @json($approveUrlTpl);
		const pendingData = @json($pendingRows);

		let currentUpdateUrl = '';
		let currentApproveUrl = '';
		let currentPendingId = 0;
		let similarReq = 0;
		let similarTimer = null;

		const entityPickerCfg = {
			hidden: document.getElementById('edit_entity_id'),
			search: document.getElementById('edit_entity_search'),
			results: document.getElementById('edit_entity_results'),
			clearBtn: document.getElementById('edit_entity_clear'),
			hint: document.getElementById('editEntityHint'),
			searchUrl: entitySearchUrl,
			fallbackPrefix: 'Entity #',
			searchLimit: 40,
			minChars: 0,
			onChange: null,
		};

		function initRefPicker(cfg) {
			let reqSeq = 0;
			let selectedLabel = '';
			let committedId = '';
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
				committedId = String(id);
				cfg.hidden.value = committedId;
				cfg.search.value = label;
				selectedLabel = label;
				refreshHint();
				hideResults();
				if (typeof cfg.onChange === 'function') {
					cfg.onChange();
				}
			}

			function ensureHiddenForSubmit() {
				if (!cfg.hidden) return;
				const current = (cfg.hidden.value || '').trim();
				if (current !== '' && current !== '0') return;
				if (committedId !== '' && committedId !== '0') {
					cfg.hidden.value = committedId;
					refreshHint();
				}
			}

			async function setFromId(raw) {
				if (!cfg.hidden || !cfg.search) return;
				selectedLabel = '';
				hideResults();
				const idStr = raw != null && String(raw) !== '' && String(raw) !== '0' ? String(raw) : '';
				committedId = idStr;
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
						if (typeof cfg.onChange === 'function') {
							cfg.onChange();
						}
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
				committedId = '';
				refreshHint();
				hideResults();
				if (typeof cfg.onChange === 'function') {
					cfg.onChange();
				}
			});

			return { setFromId, hideResults, ensureHiddenForSubmit };
		}

		const entityPicker = initRefPicker(entityPickerCfg);

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

		const categoryPicker = initRefPicker({
			hidden: document.getElementById('edit_category_id'),
			search: document.getElementById('edit_category_search'),
			results: document.getElementById('edit_category_results'),
			clearBtn: document.getElementById('edit_category_clear'),
			hint: document.getElementById('editCategoryHint'),
			searchUrl: categorySearchUrl,
			fallbackPrefix: 'Category #',
			searchLimit: 60,
			minChars: 0,
		});

		const bidUrlPicker = initRefPicker({
			hidden: document.getElementById('edit_bid_url_id'),
			search: document.getElementById('edit_bid_url_search'),
			results: document.getElementById('edit_bid_url_results'),
			clearBtn: document.getElementById('edit_bid_url_clear'),
			hint: document.getElementById('editBidUrlHint'),
			searchUrl: bidUrlSearchUrl,
			fallbackPrefix: 'Bid URL #',
			searchLimit: 40,
			minChars: 0,
		});

		function escHtml(str) {
			const d = document.createElement('div');
			d.textContent = str == null ? '' : String(str);
			return d.innerHTML;
		}

		function scheduleSimilarRefresh() {
			clearTimeout(similarTimer);
			similarTimer = setTimeout(refreshSimilarPanel, 350);
		}

		async function refreshSimilarPanel() {
			const loading = document.getElementById('similarLoading');
			const list = document.getElementById('similarList');
			const empty = document.getElementById('similarEmpty');
			const label = document.getElementById('similarMatchLabel');
			if (!loading || !list || !empty || !label) return;

			const entityId = parseInt(document.getElementById('edit_entity_id')?.value || '0', 10) || 0;
			const email = (document.getElementById('edit_email')?.value || '').trim();
			const url = (document.getElementById('edit_url')?.value || '').trim();

			similarReq += 1;
			const seq = similarReq;
			loading.hidden = false;
			list.hidden = true;
			empty.hidden = true;

			const params = new URLSearchParams({
				entity_id: String(entityId),
				email: email,
				url: url,
				exclude_temp_id: String(currentPendingId || 0),
			});

			let data;
			try {
				const r = await fetch(similarUrl + '?' + params.toString(), {
					headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
				});
				data = await r.json();
			} catch (e) {
				if (seq !== similarReq) return;
				loading.hidden = true;
				label.textContent = 'Could not load similar bids.';
				empty.hidden = false;
				return;
			}
			if (seq !== similarReq) return;

			loading.hidden = true;
			const entries = Array.isArray(data.entries) ? data.entries : [];
			label.textContent = data.match_label || 'No similar bids found';

			if (!entries.length) {
				list.hidden = true;
				empty.hidden = false;
				return;
			}

			list.innerHTML = '';
			entries.forEach(function (row) {
				const li = document.createElement('li');
				li.className = 'similar-item';
				const badge = row.source === 'live' ? 'live' : 'pending';
				const title = row.title || 'Untitled';
				const titleHtml = row.id
					? '<button type="button" class="similar-detail-link" data-source="' + escHtml(badge) + '" data-id="' + escHtml(String(row.id)) + '">' + escHtml(title) + '</button>'
					: escHtml(title);
				const bits = [];
				if (row.end_date) bits.push(['Ends', row.end_date]);
				if (row.scraped) bits.push(['Scraped', row.scraped]);
				if (row.email) bits.push(['Email', row.email]);
				let dl = '';
				bits.forEach(function (pair) {
					dl += '<dt>' + escHtml(pair[0]) + '</dt><dd>' + escHtml(pair[1]) + '</dd>';
				});
				li.innerHTML =
					'<span class="similar-badge ' + badge + '">' + (badge === 'live' ? 'Live' : 'Pending') + '</span>' +
					'<p class="similar-item-title">' + titleHtml + '</p>' +
					(dl ? '<dl class="similar-item-dl">' + dl + '</dl>' : '');
				list.appendChild(li);
			});
			list.hidden = false;
			empty.hidden = true;
		}

		entityPickerCfg.onChange = scheduleSimilarRefresh;
		document.getElementById('edit_email')?.addEventListener('input', scheduleSimilarRefresh);
		document.getElementById('edit_url')?.addEventListener('input', scheduleSimilarRefresh);

		document.getElementById('similarList')?.addEventListener('click', function (e) {
			const btn = e.target.closest('.similar-detail-link');
			if (!btn) return;
			e.preventDefault();
			openSimilarBidDetail(btn.dataset.source, btn.dataset.id);
		});

		function formatDetailDate(dateStr) {
			if (!dateStr) return 'N/A';
			try {
				const d = new Date(dateStr);
				if (isNaN(d.getTime())) return dateStr;
				const months = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
				return months[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, '0') + ', ' + d.getFullYear();
			} catch (err) {
				return dateStr;
			}
		}

		function renderDetailDescription(desc) {
			const descBox = document.getElementById('similar_detail_description');
			if (!descBox) return;
			const text = (desc || '').trim();
			if (!text) {
				descBox.innerHTML = '<p style="margin:0; color:#6b7280;">No details available.</p>';
				return;
			}
			const lines = text.split(/\r?\n+/).filter((l) => l.trim());
			let html = '';
			lines.forEach((line, i) => {
				const bg = i % 2 === 0 ? 'background:#f3f4f6;' : '';
				const colonIdx = line.indexOf(':');
				if (colonIdx > 0 && colonIdx < 60) {
					const label = line.substring(0, colonIdx).trim();
					const value = line.substring(colonIdx + 1).trim();
					html += '<div style="display:grid; grid-template-columns:200px 1fr; gap:0.5rem; padding:0.5rem 0.65rem; border-radius:6px; ' + bg + ' align-items:start;">'
						+ '<span style="font-weight:700; color:#1f2937; font-size:0.9rem;">' + escHtml(label) + '</span>'
						+ '<span style="color:#0f172a; white-space:pre-wrap;">' + escHtml(value) + '</span></div>';
				} else {
					html += '<div style="padding:0.5rem 0.65rem; border-radius:6px; ' + bg + '">'
						+ '<span style="color:#0f172a; white-space:pre-wrap;">' + escHtml(line) + '</span></div>';
				}
			});
			descBox.innerHTML = html;
		}

		function openSimilarBidDetailModal(bid) {
			const modal = document.getElementById('similarDetailModal');
			if (!modal || !bid) return;
			const isLive = bid.source === 'live';
			const badge = document.getElementById('similar_detail_badge');
			if (badge) {
				badge.textContent = isLive ? 'Live' : 'Pending';
				badge.className = 'similar-badge ' + (isLive ? 'live' : 'pending');
			}
			document.getElementById('similar_detail_title').textContent = bid.TITLE || 'Untitled bid';
			document.getElementById('similar_detail_enddate').textContent = bid.ENDDATE ? formatDetailDate(bid.ENDDATE) : 'N/A';
			document.getElementById('similar_detail_naics').textContent = bid.NAICSCODE || 'N/A';
			document.getElementById('similar_detail_created').textContent = bid.CREATED ? formatDetailDate(bid.CREATED) : 'N/A';
			document.getElementById('similar_detail_entity').textContent = bid.entity_label || (bid.ENTITYID ? ('#' + bid.ENTITYID) : 'N/A');
			document.getElementById('similar_detail_email').textContent = bid.EMAIL || 'N/A';
			const urlEl = document.getElementById('similar_detail_url');
			const url = bid.URL || '';
			urlEl.href = url || '#';
			urlEl.textContent = url || 'N/A';
			renderDetailDescription(bid.DESCRIPTION);
			modal.showModal();
		}

		async function openSimilarBidDetail(source, id) {
			if (!source || !id) return;
			const url = source === 'live'
				? liveDetailUrlTpl.replace('__ID__', encodeURIComponent(id))
				: pendingDetailUrlTpl.replace('__ID__', encodeURIComponent(id));
			try {
				const r = await fetch(url, {
					headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
				});
				if (!r.ok) throw new Error('HTTP ' + r.status);
				const bid = await r.json();
				openSimilarBidDetailModal(bid);
			} catch (err) {
				window.alert('Could not load bid details. Please try again.');
			}
		}

		function syncEditFormSubmitTarget(isApprove) {
			const form = document.getElementById('editForm');
			const methodInput = document.getElementById('edit_method');
			const saveBtn = document.getElementById('saveChangesBtn');
			const approveBtn = document.getElementById('saveApproveBtn');
			if (!form) return;
			if (saveBtn) saveBtn.setAttribute('formaction', currentUpdateUrl);
			if (approveBtn) approveBtn.setAttribute('formaction', currentApproveUrl);
			if (isApprove) {
				form.action = currentApproveUrl;
				if (methodInput) methodInput.value = 'POST';
			} else {
				form.action = currentUpdateUrl;
				if (methodInput) methodInput.value = 'PUT';
			}
		}

		let pendingEditSubmitIsApprove = false;

		function prepareEditFormSubmit(isApprove) {
			syncEditFormSubmitTarget(isApprove);
			const approveAction = document.getElementById('edit_approve_action');
			if (approveAction) {
				approveAction.value = isApprove ? '1' : '';
			}
			entityPicker.ensureHiddenForSubmit();
			statePicker.ensureHiddenForSubmit();
			categoryPicker.ensureHiddenForSubmit();
			bidUrlPicker.ensureHiddenForSubmit();
			['edit_entity_id', 'edit_state_id', 'edit_bid_url_id', 'edit_category_id'].forEach(function (id) {
				const el = document.getElementById(id);
				if (el && String(el.value).trim() === '0') {
					el.value = '';
				}
			});
		}

		document.getElementById('saveChangesBtn')?.addEventListener('click', function () {
			pendingEditSubmitIsApprove = false;
			prepareEditFormSubmit(false);
			document.getElementById('editForm')?.requestSubmit();
		});

		document.getElementById('saveApproveBtn')?.addEventListener('click', function () {
			pendingEditSubmitIsApprove = true;
			prepareEditFormSubmit(true);
			document.getElementById('editForm')?.requestSubmit();
		});

		document.getElementById('editForm')?.addEventListener('submit', function () {
			prepareEditFormSubmit(pendingEditSubmitIsApprove);
		});

		async function openEdit(idx) {
			const bid = pendingData[idx];
			if (!bid) return;
			const form = document.getElementById('editForm');
			currentPendingId = bid.id;
			currentUpdateUrl = updateUrlTpl.replace('__ID__', bid.id);
			currentApproveUrl = approveUrlTpl.replace('__ID__', bid.id);
			pendingEditSubmitIsApprove = false;
			const approveAction = document.getElementById('edit_approve_action');
			if (approveAction) approveAction.value = '';
			syncEditFormSubmitTarget(false);

			document.getElementById('edit_title').value = bid.TITLE || '';
			document.getElementById('edit_description').value = bid.DESCRIPTION || '';
			document.getElementById('edit_email').value = bid.EMAIL || '';
			document.getElementById('edit_url').value = bid.URL || '';
			document.getElementById('edit_enddate').value = bid.ENDDATE || '';
			document.getElementById('edit_naics').value = bid.NAICSCODE || '';
			const userSel = document.getElementById('edit_userid');
			userSel.value = bid.USERID != null && String(bid.USERID) !== '0' ? String(bid.USERID) : '';

			await entityPicker.setFromId(bid.ENTITYID);
			await statePicker.setFromId(bid.STATEID);
			await categoryPicker.setFromId(bid.CATEGORYID);
			await bidUrlPicker.setFromId(bid.BID_URL_ID);
			document.getElementById('editModal').showModal();
			refreshSimilarPanel();
		}
	</script>
</body>

</html>
