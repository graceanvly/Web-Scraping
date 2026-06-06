<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Bid URLs</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		:root {
			--card-bg: #fff;
			--card-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
			--muted: #6b7280;
			--border: #e5e7eb;
			--accent: #2563eb;
			--accent-strong: #1d4ed8;
			--page-bg: #f6f7fb;
		}

		html,
		body {
			margin: 0;
			padding: 0;
			min-height: 100vh;
			background: var(--page-bg);
			font-family: system-ui, sans-serif;
		}

		nav.toolbar {
			display: flex;
			flex-direction: column;
			gap: 0.35rem;
			margin-bottom: 1.25rem;
		}

		h1 {
			margin: 0;
			font-size: 2rem;
			font-weight: 600;
		}

		p.lead {
			margin: 0;
			color: var(--muted);
		}

		.card {
			background: var(--card-bg);
			padding: 1.5rem;
			border-radius: 10px;
			border: 1px solid var(--border);
			box-shadow: var(--card-shadow);
			margin-bottom: 1.25rem;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			background: var(--card-bg);
			border-radius: 8px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
		}

		th,
		td {
			padding: 0.75rem 1rem;
			text-align: left;
			vertical-align: middle;
			white-space: normal;
			border-bottom: 1px solid var(--border);
			word-break: break-word;
		}

		thead {
			background: #f0f2f5;
			font-weight: 600;
			color: #1d4ed8;
		}

		tbody tr:nth-child(even) {
			background: #fafafa;
		}

		tbody tr:hover {
			background: #f1f5f9;
		}

		.alert {
			padding: 0.75rem 1rem;
			border-radius: 8px;
			margin-bottom: 1rem;
			border: 1px solid transparent;
			font-size: 0.95rem;
		}

		.alert.success {
			background: #ecfdf3;
			border-color: #bbf7d0;
			color: #166534;
		}

		.alert.error {
			background: #fef2f2;
			border-color: #fecaca;
			color: #991b1b;
		}

		.actions {
			display: flex;
			gap: 0.5rem;
			flex-wrap: nowrap;
			align-items: center;
			white-space: nowrap;
		}

		.actions form {
			display: inline-flex;
			margin: 0;
		}

		.row-actions {
			display: flex;
			gap: 0.35rem;
			flex-wrap: nowrap;
			align-items: center;
			justify-content: flex-end;
		}

		.icon-action {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 2rem;
			height: 2rem;
			padding: 0;
			margin: 0;
			min-width: auto;
			border-radius: 6px;
			border: 1px solid transparent;
			cursor: pointer;
			background: transparent;
		}

		.icon-action svg {
			width: 1.1rem;
			height: 1.1rem;
		}

		.icon-action--view {
			color: #2563eb;
			border-color: #bfdbfe;
			background: #eff6ff;
		}

		.icon-action--view:hover {
			background: #dbeafe;
		}

		.icon-action--edit {
			color: #475569;
			border-color: #e2e8f0;
			background: #f8fafc;
		}

		.icon-action--edit:hover {
			background: #e2e8f0;
		}

		.icon-action--restore {
			color: #16a34a;
			border-color: #bbf7d0;
			background: #f0fdf4;
		}

		.icon-action--restore:hover {
			background: #dcfce7;
		}

		.icon-action--delete {
			color: #b91c1c;
			border-color: #fecaca;
			background: #fef2f2;
		}

		.icon-action--delete:hover {
			background: #fee2e2;
		}

		.col-date {
			white-space: nowrap;
			width: 4.25rem;
			font-size: 0.88rem;
			font-variant-numeric: tabular-nums;
		}

		.col-actions {
			width: 8.75rem;
			text-align: right;
			white-space: nowrap;
		}

		.icon-action--add {
			color: #16a34a;
			border-color: #bbf7d0;
			background: #f0fdf4;
		}

		.icon-action--add:hover {
			background: #dcfce7;
		}

		.muted { color: var(--muted); }

		dialog#manualBidModal {
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

		dialog#manualBidModal[open] {
			display: flex !important;
			flex-direction: column;
		}

		dialog#manualBidModal::backdrop { background: rgba(15, 23, 42, 0.45); }

		.manual-modal-shell {
			width: 100%;
			padding: 2rem 2.25rem;
			overflow-y: auto;
			box-sizing: border-box;
			background: #fff;
		}

		.manual-modal-layout {
			display: grid;
			grid-template-columns: minmax(0, 1fr) minmax(280px, 380px);
			gap: 2rem;
			align-items: start;
		}

		.manual-edit-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 1.1rem 1.25rem;
		}

		.manual-edit-grid .full { grid-column: 1 / -1; }

		.manual-modal-footer {
			display: flex;
			justify-content: space-between;
			gap: 0.75rem;
			margin-top: 1.5rem;
			flex-wrap: wrap;
		}

		.manual-modal-close {
			background: none;
			border: none;
			font-size: 1.5rem;
			cursor: pointer;
			color: #6b7280;
			padding: 0;
			line-height: 1;
		}

		.ref-picker { position: relative; }

		.ref-picker-results {
			position: absolute;
			left: 0;
			right: 0;
			top: 100%;
			z-index: 30;
			margin: 0.2rem 0 0;
			padding: 0;
			list-style: none;
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			max-height: 240px;
			overflow-y: auto;
			box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
		}

		.ref-picker-results li { padding: 0.5rem 0.7rem; cursor: pointer; font-size: 0.85rem; }

		.ref-picker-results li:hover { background: #eff6ff; }

		.ref-picker-clear {
			margin: 0;
			padding: 0;
			border: none;
			background: none;
			cursor: pointer;
			color: #2563eb;
			text-decoration: underline;
			font-size: 0.82rem;
		}

		.similar-panel {
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			border-radius: 10px;
			padding: 1rem 1.1rem;
			position: sticky;
			top: 0;
			max-height: calc(100dvh - 8rem);
			overflow-y: auto;
		}

		.similar-panel h4 { margin: 0 0 0.35rem; font-size: 0.88rem; }

		.similar-meta, .similar-loading, .similar-empty { font-size: 0.78rem; color: #64748b; }

		.similar-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.55rem; }

		.similar-item {
			background: #fff;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 0.55rem 0.6rem;
			font-size: 0.78rem;
		}

		.similar-badge {
			display: inline-block;
			font-size: 0.65rem;
			font-weight: 700;
			text-transform: uppercase;
			padding: 0.1rem 0.35rem;
			border-radius: 4px;
		}

		.similar-badge.live { background: #eff6ff; color: #1d4ed8; }

		.similar-badge.pending { background: #fff7ed; color: #c2410c; }

		.similar-detail-link {
			background: none;
			border: none;
			padding: 0;
			color: #2563eb;
			font: inherit;
			font-weight: 600;
			cursor: pointer;
			text-decoration: underline;
		}

		dialog#manualSimilarDetailModal {
			max-width: 820px;
			width: min(92vw, 820px);
			border: none;
			border-radius: 12px;
			padding: 0;
		}

		.similar-detail-shell { padding: 2rem; max-height: 90dvh; overflow-y: auto; background: #fff; }

		.similar-detail-meta {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 1rem;
			margin-bottom: 1rem;
		}

		.similar-detail-meta .meta-card {
			background: #f9fafb;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 0.85rem;
		}

		@media (max-width: 1024px) {
			.manual-modal-layout { grid-template-columns: 1fr; }
			.manual-edit-grid { grid-template-columns: 1fr; }
		}

		.table-wrapper {
			overflow-x: auto;
		}

		.table-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 0.75rem;
			margin: 0.75rem 0;
		}

		.table-toolbar .controls {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			flex-wrap: nowrap;
		}

		.table-toolbar input,
		.table-toolbar select {
			min-height: 2.2rem;
			max-width: 350px;
		}

		.header-actions {
			display: flex;
			gap: 0.6rem;
			align-items: center;
			justify-content: flex-end;
			flex-wrap: nowrap;
			white-space: nowrap;
			margin-left: auto;
		}

		.toolbar {
			display: flex;
			flex-direction: column;
			gap: 0.35rem;
			margin-bottom: 1rem;
		}

		.toolbar-main {
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: nowrap;
			gap: 1rem;
		}

		.btn {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 0.5rem;
			padding: 0.5rem 0.85rem;
			min-height: 2.3rem;
			border-radius: 8px;
			font-weight: 600;
			font-size: 0.9rem;
			line-height: 1.1;
			border: 1px solid transparent;
			text-decoration: none;
			cursor: pointer;
			box-shadow: none;
		}

		.btn-primary {
			background: var(--accent);
			color: #fff;
			border-color: var(--accent-strong);
		}

		.btn-primary:hover {
			background: var(--accent-strong);
		}

		.btn-secondary {
			background: #f8fafc;
			color: #0f172a;
			border-color: #d7dde5;
		}

		.btn-secondary:hover {
			background: #e7ecf3;
		}

		.btn-ghost {
			background: transparent;
			color: #1f2937;
			border-color: transparent;
		}

		.btn-ghost:hover {
			background: #f1f5f9;
			border-color: #e2e8f0;
		}

		.header-actions {
			align-items: center;
		}

		.btn:focus-visible {
			outline: 2px solid #cbd5ff;
			outline-offset: 2px;
		}

		.nav-back {
			display: inline-flex;
			align-items: center;
			gap: 0.35rem;
			color: #1f2937;
			text-decoration: none;
			font-weight: 600;
			margin-bottom: 0.25rem;
		}

		.nav-back:hover {
			color: #111827;
		}

		.badge {
			display: inline-flex;
			align-items: center;
			padding: 0.2rem 0.55rem;
			border-radius: 999px;
			background: #eef2ff;
			color: #4338ca;
			font-weight: 600;
			font-size: 0.8rem;
		}

		.section-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 0.75rem;
			flex-wrap: wrap;
			margin-bottom: 0.75rem;
		}

		.section-title {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			flex-wrap: wrap;
		}

		.footer-bar {
			margin-top: 0.75rem;
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 0.5rem;
			color: #4b5563;
			font-size: 0.95rem;
		}

		.pagination {
			display: flex;
			justify-content: flex-end;
		}

		.pagination ul {
			display: flex;
			gap: 0.35rem;
			list-style: none;
			margin: 0;
			padding: 0;
			align-items: center;
			flex-wrap: wrap;
		}

		.pagination li {
			margin: 0;
		}

		.pagination a,
		.pagination span {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 2rem;
			height: 2rem;
			padding: 0 0.5rem;
			border: 1px solid var(--border);
			border-radius: 6px;
			text-decoration: none;
			color: #0f172a;
			background: #fff;
			font-size: 0.9rem;
		}

		.pagination .active span {
			background: var(--accent);
			color: #fff;
			border-color: var(--accent-strong);
		}

		.pagination .disabled span {
			background: #f8fafc;
			color: #9ca3af;
		}

		th:nth-child(3),
		td:nth-child(3) {
			width: auto;
		}

		@media (max-width: 768px) {
			table {
				font-size: 0.8rem;
			}

			th,
			td {
				padding: 0.5rem 0.6rem;
			}

			.actions {
				flex-wrap: nowrap;
			}

			.header-actions {
				flex-wrap: wrap;
				justify-content: flex-start;
				margin-left: 0;
			}
		}

		@media (max-width: 1024px) {
			.header-actions {
				flex-wrap: wrap;
				justify-content: flex-start;
				margin-left: 0;
				gap: 0.5rem;
			}

			.section-header {
				align-items: flex-start;
			}

			.toolbar-main {
				flex-wrap: wrap;
				align-items: flex-start;
				gap: 0.6rem;
			}

			.table-toolbar {
				flex-direction: column;
				align-items: flex-start;
			}

			.table-toolbar .controls {
				flex-wrap: wrap;
			}
		}
	</style>
</head>

<body>
	<main class="container">
		<nav class="toolbar">
			<a class="nav-back" href="{{ route('bids.index') }}">
				<span aria-hidden="true">&#8592;</span>
				<span>Back to Bids</span>
			</a>
			<div class="toolbar-main">
				<div>
					<h1>Bid URLs</h1>
				</div>
			</div>
			<p class="lead">Manage the URLs you scrape for bids: add, edit, delete, or review details.</p>
		</nav>

		@if (session('success'))
			<div class="alert success">{{ session('success') }}</div>
		@endif
		@if ($errors->any())
			<div class="alert error">
				<ul style="margin:0; padding-left:1.1rem;">
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		@endif

		<section class="card">
			<div class="section-header">
				<div class="section-title">
					<h2 style="margin:0;">Configured URLs</h2>
					<span class="badge">{{ $bidUrls->total() }} total</span>
				</div>
				<div class="header-actions">
					<button class="btn btn-secondary" type="button" onclick="openSetLastScraped()">Set Last Scraped</button>
					<button class="btn btn-primary" type="button" onclick="openAdd()">+ Add Bid URL</button>
				</div>
			</div>
			<div class="table-wrapper">
				<form id="filtersForm" method="GET" action="{{ route('bidurl.index') }}" class="table-toolbar">
					<div class="controls">
						<label style="display:flex; align-items:center; gap:0.35rem; margin:0;">
							Show
							<select id="showEntries" name="per_page">
								<option value="5" {{ request('per_page') == 5 ? 'selected' : '' }}>5</option>
								<option value="10" {{ request('per_page') == 10 ? 'selected' : '' }}>10</option>
								<option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
								<option value="50" {{ request('per_page', 50) == 50 ? 'selected' : '' }}>50</option>
								<option value="100" {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
							</select>
							entries
						</label>
					</div>
					<div class="controls">
						<input type="search" id="searchInput" name="search" value="{{ $search }}" placeholder="Search URL or name">
						@if ($search !== '')
							<a href="{{ route('bidurl.index', ['per_page' => request('per_page', 50)]) }}" class="btn btn-secondary">Clear</a>
						@endif
					</div>
				</form>
				<table>
					<thead>
						<tr>
							<th>URL</th>
							<th>Name</th>
							<th class="col-date">Last Scraped</th>
							<th class="col-actions">Actions</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($bidUrls as $bidUrl)
							<tr>
								<td><a href="{{ $bidUrl->url }}" target="_blank" rel="noreferrer">{{ $bidUrl->url }}</a></td>
								<td>{{ $bidUrl->name ?? '-' }}</td>
								<td class="col-date">
									@if ($bidUrl->last_scraped_at)
										<span style="color: {{ $bidUrl->last_scraped_at->isToday() ? '#16a34a' : '#6b7280' }};"
											title="{{ $bidUrl->last_scraped_at->format('M j, Y g:i A') }}">
											{{ $bidUrl->last_scraped_at->format('n/j') }}
										</span>
									@else
										<span style="color:#9ca3af;">Never</span>
									@endif
								</td>
								<td class="col-actions">
									<div class="row-actions">
										@if (\App\Services\BidUrlManualEntryService::showAddButton($bidUrl->last_scraped_at))
											@php
												$manualAddConfig = [
													'startUrl' => route('bidurl.manualBid.start', $bidUrl),
													'storeUrl' => route('bidurl.manualBid.store', $bidUrl),
													'cancelUrl' => route('bidurl.manualBid.cancel', $bidUrl),
													'listingUrl' => $bidUrl->url,
												];
											@endphp
											<button type="button" class="icon-action icon-action--add" title="Add bid manually" aria-label="Add bid manually"
												data-manual-add="@json($manualAddConfig)"
												onclick="openManualBidFromBtn(this)">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
											</button>
										@endif
										<button class="icon-action icon-action--view" type="button" onclick='openDetails(@json($bidUrl))' title="View details" aria-label="View details">
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
										</button>
										<button class="icon-action icon-action--edit" type="button" onclick='openEdit(@json($bidUrl))' title="Edit" aria-label="Edit">
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
										</button>
										<form method="POST" action="{{ route('bidurl.destroy', $bidUrl) }}" onsubmit="return confirm('Delete this Bid URL?')">
											@csrf
											@method('DELETE')
											<button class="icon-action icon-action--delete" type="submit" title="Delete" aria-label="Delete">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
											</button>
										</form>
									</div>
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="4" style="text-align:center; color:#6b7280;">No Bid URLs found.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			<div class="footer-bar">
				<div>Showing <span id="showingCount">{{ $bidUrls->count() }}</span> of <span id="totalCount">{{ $bidUrls->total() }}</span> entries</div>
				{{ $bidUrls->links('pagination.bidurl') }}
			</div>
		</section>

		<section class="card">
			<div class="section-header">
				<div class="section-title">
					<h2 style="margin:0;">Scrape Failed URLs</h2>
					<span class="badge">{{ $failedCount }} total</span>
				</div>
				@if ($failedCount > 0)
					<div class="header-actions">
						<form method="POST" action="{{ route('failed-bidurl.restoreAll') }}"
							onsubmit="return confirm('Restore ALL failed URLs to the Bid URL list? URLs that are already on the active list will be removed from failed only.')"
							style="margin:0;">
							@csrf
							<button class="btn btn-secondary" type="submit">Restore All</button>
						</form>
					</div>
				@endif
			</div>
			<div class="table-wrapper">
				<table>
					<thead>
						<tr>
							<th>URL</th>
							<th>Name</th>
							<th>Last Error</th>
							<th class="col-date">Failed At</th>
							<th class="col-actions">Actions</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($failedBidUrls as $bidUrl)
							<tr>
								<td><a href="{{ $bidUrl->url }}" target="_blank" rel="noreferrer">{{ $bidUrl->url }}</a></td>
								<td>{{ $bidUrl->name ?? '-' }}</td>
								<td>{{ $bidUrl->failure_message ?? '-' }}</td>
								<td class="col-date">
									@if ($bidUrl->failed_at)
										<span style="color:#b91c1c;"
											title="{{ $bidUrl->failed_at->format('M j, Y g:i A') }}">
											{{ $bidUrl->failed_at->format('n/j') }}
										</span>
									@else
										<span style="color:#9ca3af;">-</span>
									@endif
								</td>
								<td class="col-actions">
									<div class="row-actions">
										@if (\App\Services\BidUrlManualEntryService::showAddButton($bidUrl->last_scraped_at))
											@php
												$manualAddConfig = [
													'startUrl' => route('failed-bidurl.manualBid.start', $bidUrl),
													'storeUrl' => route('failed-bidurl.manualBid.store', $bidUrl),
													'cancelUrl' => route('failed-bidurl.manualBid.cancel', $bidUrl),
													'listingUrl' => $bidUrl->url,
												];
											@endphp
											<button type="button" class="icon-action icon-action--add" title="Add bid manually" aria-label="Add bid manually"
												data-manual-add="@json($manualAddConfig)"
												onclick="openManualBidFromBtn(this)">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
											</button>
										@endif
										<button class="icon-action icon-action--view" type="button" onclick='openDetails(@json($bidUrl))' title="View details" aria-label="View details">
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
										</button>
										<form method="POST" action="{{ route('failed-bidurl.restore', $bidUrl) }}" onsubmit="return confirm('Restore this failed URL to the Bid URL list?')">
											@csrf
											<button class="icon-action icon-action--restore" type="submit" title="Restore to Bid URLs" aria-label="Restore to Bid URLs">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
											</button>
										</form>
										<form method="POST" action="{{ route('failed-bidurl.destroy', $bidUrl) }}" onsubmit="return confirm('Delete this failed URL?')">
											@csrf
											@method('DELETE')
											<button class="icon-action icon-action--delete" type="submit" title="Delete" aria-label="Delete">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
											</button>
										</form>
									</div>
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="5" style="text-align:center; color:#6b7280;">No failed scrape URLs.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			@if ($failedCount > 0)
				<div class="footer-bar">
					<div>Showing {{ $failedBidUrls->count() }} of {{ $failedBidUrls->total() }} failed entries</div>
					{{ $failedBidUrls->links('pagination.bidurl') }}
				</div>
			@endif
		</section>
	</main>

	<dialog id="detailsModal">
		<article>
			<h3>Bid URL Details</h3>
			<div id="detailsContent"></div>
			<footer style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
				<button class="secondary" type="button" onclick="detailsModal.close()">Close</button>
			</footer>
		</article>
	</dialog>

	<dialog id="addModal">
		<article>
			<h3>Add Bid URL</h3>
			<form id="addForm" method="POST" action="{{ route('bidurl.storeSingle') }}">
				@csrf
				<label for="add_url">URL</label>
				<input type="url" id="add_url" name="url" placeholder="https://example.gov/bids" required>

				<label for="add_name">Name (optional)</label>
				<input type="text" id="add_name" name="name" placeholder="Optional name">

				<label for="add_username">Username (optional)</label>
				<input type="text" id="add_username" name="username" placeholder="Optional username">

				<label for="add_password">Password (optional)</label>
				<input type="text" id="add_password" name="password" placeholder="Optional password">

				<footer style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
					<button class="secondary" type="button" onclick="addModal.close()">Cancel</button>
					<button class="contrast" type="submit">Add</button>
				</footer>
			</form>
		</article>
	</dialog>

	<dialog id="setLastScrapedModal">
		<article>
			<h3>Set Last Scraped</h3>
			<p style="margin:0 0 1rem; color:#6b7280; font-size:0.9rem;">
				Applies to <strong>all</strong> configured Bid URLs. Use this to reset scrape eligibility (e.g. clear so Scrape All retries every URL).
			</p>
			<form id="setLastScrapedForm" method="POST" action="{{ route('bidurl.setLastScraped') }}"
				onsubmit="return confirm('Apply this last scraped date to ALL Bid URLs?')">
				@csrf

				<label for="set_last_scraped_at">Last Scraped</label>
				<input type="datetime-local" id="set_last_scraped_at" name="last_scraped_at">

				<label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.75rem;">
					<input type="checkbox" id="clear_last_scraped" name="clear_last_scraped" value="1">
					Clear last scraped on all URLs (set to Never)
				</label>

				<footer style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
					<button class="secondary" type="button" onclick="setLastScrapedModal.close()">Cancel</button>
					<button class="contrast" type="submit">Apply to all</button>
				</footer>
			</form>
		</article>
	</dialog>

	<dialog id="editModal">
		<article>
			<h3>Edit Bid URL</h3>
			<form id="editForm" method="POST">
				@csrf
				@method('PUT')
				<label for="edit_url">URL</label>
				<input type="url" id="edit_url" name="url" required>

				<label for="edit_name">Name</label>
				<input type="text" id="edit_name" name="name">

				<label for="edit_username">Username (optional)</label>
				<input type="text" id="edit_username" name="username">

				<label for="edit_password">Password (optional)</label>
				<input type="text" id="edit_password" name="password">

				<footer style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
					<button class="secondary" type="button" onclick="editModal.close()">Cancel</button>
					<button class="contrast" type="submit">Save</button>
				</footer>
			</form>
		</article>
	</dialog>

	@include('bidurl.partials.manual-bid-modal')

	<script>
		const detailsModal = document.getElementById('detailsModal');
		const detailsContent = document.getElementById('detailsContent');
		const addModal = document.getElementById('addModal');
		const setLastScrapedModal = document.getElementById('setLastScrapedModal');
		const setLastScrapedAtInput = document.getElementById('set_last_scraped_at');
		const clearLastScrapedInput = document.getElementById('clear_last_scraped');
		const editModal = document.getElementById('editModal');
		const editForm = document.getElementById('editForm');
		const filtersForm = document.getElementById('filtersForm');
		const table = document.querySelector('table tbody') ? document.querySelector('table') : null;
		const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
		const searchInput = document.getElementById('searchInput');
		const showEntries = document.getElementById('showEntries');
		const showingCount = document.getElementById('showingCount');

		function openAdd() {
			document.getElementById('add_url').value = '';
			document.getElementById('add_name').value = '';
			document.getElementById('add_username').value = '';
			document.getElementById('add_password').value = '';
			addModal.showModal();
		}

		function formatDateTimeLocal(date) {
			const pad = (value) => String(value).padStart(2, '0');
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
		}

		function openSetLastScraped() {
			clearLastScrapedInput.checked = false;
			setLastScrapedAtInput.disabled = false;
			setLastScrapedAtInput.required = true;
			setLastScrapedAtInput.value = formatDateTimeLocal(new Date());
			setLastScrapedModal.showModal();
		}

		clearLastScrapedInput?.addEventListener('change', () => {
			const clear = clearLastScrapedInput.checked;
			setLastScrapedAtInput.disabled = clear;
			setLastScrapedAtInput.required = !clear;
			if (clear) {
				setLastScrapedAtInput.value = '';
			} else if (!setLastScrapedAtInput.value) {
				setLastScrapedAtInput.value = formatDateTimeLocal(new Date());
			}
		});

		function openDetails(bidUrl) {
			const failureFields = bidUrl.failure_message ? `
          <li><strong>Failure Message:</strong> ${bidUrl.failure_message}</li>
          <li><strong>Failed At:</strong> ${bidUrl.failed_at ? bidUrl.failed_at : '&mdash;'}</li>
      ` : '';
			detailsContent.innerHTML = `
        <ul>
          <li><strong>URL:</strong> <a href="${bidUrl.url}" target="_blank" rel="noreferrer">${bidUrl.url}</a></li>
          <li><strong>Name:</strong> ${bidUrl.name ? bidUrl.name : '&mdash;'}</li>
          <li><strong>Valid:</strong> ${bidUrl.valid ? 'Yes' : 'No'}</li>
          <li><strong>Start Time:</strong> ${bidUrl.start_time ? bidUrl.start_time : '&mdash;'}</li>
          <li><strong>End Time:</strong> ${bidUrl.end_time ? bidUrl.end_time : '&mdash;'}</li>
          <li><strong>Weight:</strong> ${bidUrl.weight ? bidUrl.weight : '&mdash;'}</li>
          <li><strong>User ID:</strong> ${bidUrl.user_id ? bidUrl.user_id : '&mdash;'}</li>
          <li><strong>Check Changes:</strong> ${bidUrl.check_changes ? bidUrl.check_changes : '&mdash;'}</li>
          <li><strong>Visit Required:</strong> ${bidUrl.visit_required ? bidUrl.visit_required : '&mdash;'}</li>
          <li><strong>Checksum:</strong> ${bidUrl.checksum ? bidUrl.checksum : '&mdash;'}</li>
          <li><strong>Third Party URL ID:</strong> ${bidUrl.third_party_url_id ? bidUrl.third_party_url_id : '&mdash;'}</li>
          ${failureFields}
        </ul>
      `;
			detailsModal.showModal();
		}

		function openEdit(bidUrl) {
			editForm.action = "{{ url('/bidurl') }}/" + bidUrl.id;
			document.getElementById('edit_url').value = bidUrl.url ? bidUrl.url : '';
			document.getElementById('edit_name').value = bidUrl.name ? bidUrl.name : '';
			document.getElementById('edit_username').value = bidUrl.username ? bidUrl.username : '';
			document.getElementById('edit_password').value = bidUrl.password ? bidUrl.password : '';
			editModal.showModal();
		}

		if (table) {
			if (showingCount) {
				showingCount.textContent = rows.length;
			}

			let searchSubmitTimer;

			searchInput?.addEventListener('input', () => {
				clearTimeout(searchSubmitTimer);
				searchSubmitTimer = setTimeout(() => {
					filtersForm?.requestSubmit();
				}, 300);
			});

			showEntries?.addEventListener('change', () => {
				const pageInput = filtersForm?.querySelector('input[name="page"]');
				if (pageInput) {
					pageInput.remove();
				}
				filtersForm?.requestSubmit();
			});
		}
	</script>
</body>

</html>
