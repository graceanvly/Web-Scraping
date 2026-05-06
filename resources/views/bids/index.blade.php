<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Bid Scraper</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		html,
		body {
			margin: 0;
			padding: 0;
			min-height: 100vh;
			background: linear-gradient(135deg, #f5f7fa 0%, #e4ebf5 100%);
			font-family: system-ui, sans-serif;
		}

		nav {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 2rem;
			flex-wrap: wrap;
		}

		h1 {
			margin: 0;
			font-size: 1.8rem;
			font-weight: 600;
		}

		.card {
			background: #fff;
			padding: 1.5rem;
			border-radius: 10px;
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
			margin-bottom: 2rem;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			background: #fff;
			border-radius: 8px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
		}

		th,
		td {
			padding: 0.75rem 1rem;
			text-align: left;
			vertical-align: middle;
			white-space: nowrap;
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

		/* Title column truncate */
		td:first-child {
			max-width: 320px;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		td:nth-child(3) {
			max-width: 150px;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		td:first-child a {
			color: #337fe4ff;
			font-weight: 600;
			text-decoration: none;
		}

		td:first-child a:hover {
			text-decoration: underline;
			color: #1e40af;
		}

		.action-buttons {
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.action-buttons button,
		.action-buttons form button {
			padding: 0.4rem 0.8rem;
			font-size: 0.85rem;
			display: flex;
			align-items: center;
			gap: 0.35rem;
			border-radius: 6px;
			white-space: nowrap;
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
			color: #b91c1c;
		}

		.alert.warning {
			background: #fff7ed;
			border-color: #fed7aa;
			color: #c2410c;
		}

		.alert .close-btn {
			background: transparent;
			border: none;
			color: inherit;
			font-weight: 600;
			margin-left: auto;
			margin-right: 0;
			cursor: pointer;
			line-height: 1;
		}

		/* Toolbar */
		.table-toolbar {
			display: flex;
			flex-wrap: wrap;
			gap: 0.75rem;
			margin-bottom: 1rem;
			align-items: center;
			justify-content: space-between;
		}

		.table-toolbar label,
		.table-toolbar input,
		.table-toolbar select {
			font-size: 0.85rem;
		}

		.table-toolbar .left-controls,
		.table-toolbar .right-controls {
			display: flex;
			align-items: center;
			gap: 0.5rem;
			flex-wrap: wrap;
		}

		.table-toolbar input {
			width: auto;
			height: auto;
		}

		.table-toolbar select {
			width: auto;
		}

		.pagination-bar {
			margin-top: 0.75rem;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 0.75rem;
			flex-wrap: wrap;
			color: #555;
			font-size: 0.9rem;
		}

		.pagination-bar .pagination {
			margin-left: auto;
		}

		/* Tabs */
		.tab-btn {
			padding: 0.6rem 1.4rem;
			font-size: 0.95rem;
			font-weight: 600;
			border: 1px solid #e2e8f0;
			border-bottom: none;
			background: #f1f5f9;
			color: #64748b;
			cursor: pointer;
			border-radius: 8px 8px 0 0;
			margin-right: -1px;
			position: relative;
			transition: background 0.15s, color 0.15s;
		}
		.tab-btn:hover { background: #e2e8f0; color: #334155; }
		.tab-btn.tab-active {
			background: #fff;
			color: #1d4ed8;
			border-color: #e2e8f0;
			z-index: 1;
		}
		.tab-badge {
			display: inline-block;
			background: #dc2626;
			color: #fff;
			border-radius: 999px;
			font-size: 0.65rem;
			padding: 1px 6px;
			font-weight: 700;
			margin-left: 4px;
			vertical-align: top;
			min-width: 16px;
			text-align: center;
		}
		.issue-badge {
			display: inline-block;
			padding: 0.15rem 0.55rem;
			border-radius: 999px;
			font-size: 0.75rem;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.03em;
		}
		.issues-table {
			table-layout: fixed;
		}
		.issues-table th,
		.issues-table td {
			white-space: normal;
			vertical-align: top;
			overflow-wrap: anywhere;
			word-break: break-word;
		}
		.issues-table th:nth-child(1),
		.issues-table td:nth-child(1) {
			width: 120px;
		}
		.issues-table th:nth-child(2),
		.issues-table td:nth-child(2) {
			width: 30%;
		}
		.issues-table th:nth-child(3),
		.issues-table td:nth-child(3) {
			width: 40%;
		}
		.issues-table th:nth-child(4),
		.issues-table td:nth-child(4) {
			width: 190px;
		}
		.issues-table th:nth-child(5),
		.issues-table td:nth-child(5) {
			width: 110px;
		}
		.issues-url-link {
			color: #2563eb;
			display: inline-block;
			max-width: 100%;
			white-space: normal;
			overflow-wrap: anywhere;
			word-break: break-word;
		}
		.issue-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
		.issue-warning { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
		.issue-success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
		.issue-delete-btn {
			padding: 0.15rem 0.45rem;
			min-width: auto;
			line-height: 1;
			font-size: 0.85rem;
			border-radius: 999px;
			border: 1px solid #fecaca;
			background: #fff5f5;
			color: #b91c1c;
		}
		.issue-delete-btn:hover { background: #fee2e2; }

		/* Responsive table for mobile */
		@media (max-width: 768px) {
			table {
				font-size: 0.75rem;
				/* smaller font */
			}

			th,
			td {
				padding: 0.4rem 0.5rem;
				/* tighter spacing */
			}

			td:first-child {
				max-width: 160px;
				/* shorter title column */
			}

			td:nth-child(3) {
				max-width: 90px;
				/* NAICS smaller */
			}

			/* Shrink action buttons */
			.action-buttons button,
			.action-buttons form button {
				padding: 0.25rem 0.5rem;
				font-size: 0.7rem;
			}

			/* Toolbar compact */
			.table-toolbar {
				flex-direction: column;
				align-items: flex-start;
				gap: 0.5rem;
			}

			.table-toolbar .right-controls {
				width: 100%;
				justify-content: space-between;
			}

			.table-toolbar input,
			.table-toolbar select {
				font-size: 0.75rem;
				padding: 0.3rem 0.4rem;
				flex: 1;
			}

			th:nth-child(2),
			td:nth-child(2),
			th:nth-child(4),
			td:nth-child(4),
			th:nth-child(5),
			td:nth-child(5) {
				display: none;
			}
		}
	</style>
</head>

<body>
	<main class="container">
		<!-- Navigation -->
	<nav>
		<h1>Bid Scraper</h1>
		<div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
			<form method="POST" action="{{ route('logout') }}">
				@csrf
				<button type="submit" class="secondary">Logout</button>
			</form>
		</div>
	</nav>

		@if (session('success'))
			<div class="alert success" style="display:flex; align-items:flex-start; gap:0.5rem;">
				{{ session('success') }}
				<button type="button" class="close-btn" aria-label="Close alert" onclick="this.parentElement.remove()">×</button>
			</div>
		@endif

		@if ($errors->any())
			<div class="alert error" style="display:flex; align-items:flex-start; gap:0.5rem;">
				<strong>Scrape issues:</strong>
				<ul style="margin:0.5rem 0 0 1.25rem;">
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
				<button type="button" class="close-btn" aria-label="Close alert" onclick="this.parentElement.remove()">×</button>
			</div>
		@endif

		@if (session('scrape_issues'))
			@php $issues = session('scrape_issues') ?? []; @endphp
			@if (!empty($issues))
				<div class="alert warning" style="display:flex; align-items:flex-start; gap:0.5rem;">
					<strong>Skipped URLs:</strong>
					<ul style="margin:0.5rem 0 0 1.25rem;">
						@foreach ($issues as $issue)
							<li>{{ $issue }}</li>
						@endforeach
					</ul>
					<button type="button" class="close-btn" aria-label="Close alert" onclick="this.parentElement.remove()">×</button>
				</div>
			@endif
		@endif

		<!-- Actions -->
	<section class="card">
		<div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-start; justify-content:space-between;">
			<div style="display:flex; flex-direction:column; gap:0.35rem; align-items:flex-start;">
				<div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
					<button id="scrapeAllBtn" type="button" class="contrast"
						style="padding:0.6rem 1.2rem; font-size:0.95rem; white-space:nowrap;"
						onclick="startScrapeAll()">
						Scrape All
					</button>
					<a href="{{ route('bidurl.index') }}" class="secondary" style="white-space:nowrap;">Bid URLs</a>
				</div>
			</div>

			<form method="POST" action="{{ route('bids.store') }}"
				style="display:flex; gap:0.5rem; flex:1; max-width:600px;">
				@csrf
				<input type="URL" id="URL" name="URL" value="{{ old('URL') }}" placeholder="Enter bidding URL to scrape"
					required style="flex:1; min-width:80px; padding:0.6rem 0.75rem; font-size:0.95rem;">
				<button type="submit"
					style="flex:0; width:auto; padding:0.45rem 0.9rem; font-size:0.85rem; white-space:nowrap;">
					Scrape
				</button>
			</form>
		</div>

		<!-- Scrape Progress Panel -->
		<div id="scrapeProgress" style="display:none; margin-top:1rem; padding:1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
				<strong id="progressTitle" style="font-size:0.95rem;">Starting scrape...</strong>
				<span id="progressCounter" style="font-size:0.85rem; color:#6b7280;"></span>
			</div>
			<div style="width:100%; background:#e5e7eb; border-radius:4px; height:6px; margin-bottom:0.75rem;">
				<div id="progressBar" style="width:0%; height:100%; background:#2563eb; border-radius:4px; transition:width 0.3s;"></div>
			</div>
			<div id="progressUrl" style="font-size:0.85rem; color:#374151; word-break:break-all; min-height:1.3rem;"></div>
			<div id="progressLog" style="margin-top:0.5rem; max-height:120px; overflow-y:auto; font-size:0.8rem; color:#6b7280;"></div>
		</div>
	</section>

	<!-- Tabs -->
	<div style="display:flex; gap:0; margin-bottom:0;">
		<button id="tabBids" class="tab-btn tab-active" onclick="switchTab('bids')">
			Bids
		</button>
		<button id="tabIssues" class="tab-btn" onclick="switchTab('issues')">
			Issues
			@if (($issueCount ?? 0) > 0)
				<span class="tab-badge">{{ $issueCount }}</span>
			@endif
		</button>
	</div>

	<!-- Bids Tab -->
	<section id="panelBids" class="card" style="border-top-left-radius:0;">
			<!-- Toolbar -->
			<form id="filtersForm" method="GET" action="{{ route('bids.index') }}" class="table-toolbar">
				<div class="left-controls">
					<label style="display:flex; align-items:center; gap:0.3rem;">
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
				<div class="right-controls">
					<input type="text" id="searchInput" name="search" value="{{ $search }}" placeholder="Search…" />
					<input type="date" id="filterDate" name="date" value="{{ $filterDate }}" title="Filter by date scraped" />
					<select id="filterNaics" name="naics">
						<option value="">NAICS</option>
						@foreach ($naicsCodes as $code)
							@if ($code)
								<option value="{{ $code }}" {{ $filterNaics === $code ? 'selected' : '' }}>{{ $code }}</option>
							@endif
						@endforeach
					</select>
					@if ($search !== '' || $filterDate !== '' || $filterNaics !== '')
						<a href="{{ route('bids.index', ['per_page' => request('per_page', 50)]) }}" class="secondary">Clear</a>
					@endif
				</div>
			</form>

			@if (!empty($latestDateLabel))
				<div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.75rem; padding:0.5rem 0.75rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; font-size:0.85rem; color:#1e40af;">
					Showing bids scraped on <strong>{{ $latestDateLabel }}</strong>
					<a href="{{ route('bids.index', array_merge(request()->query(), ['all' => 1])) }}" style="margin-left:0.25rem; color:#2563eb; font-weight:600;">Show All Dates</a>
				</div>
			@endif

			<div style="overflow-x:auto;">
				<table role="grid" id="bidsTable">
					<thead>
						<tr>
							<th>Title</th>
							<th>End Date</th>
							<th>NAICS</th>
							<th>Scraped</th>
							<th>URL</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($bids as $idx => $bid)
							<tr data-scraped="{{ $bid->CREATED ? \Carbon\Carbon::parse($bid->CREATED)->toDateString() : '' }}">
								<td title="{{ $bid->TITLE }}">
									<a href="javascript:void(0)" data-bid-idx="{{ $idx }}" class="bid-detail-link">{{ $bid->TITLE ?? '—' }}</a>
								</td>
								<td>
									{{ $bid->ENDDATE ? \Carbon\Carbon::parse($bid->ENDDATE)->format('M. d, Y') : '—' }}
								</td>
								<td title="{{ $bid->NAICSCODE }}">{{ $bid->NAICSCODE ?? '—' }}</td>
								<td style="font-size:0.85rem; color:#6b7280;">
									{{ $bid->CREATED ? \Carbon\Carbon::parse($bid->CREATED)->format('M. d, Y') : '—' }}
								</td>
								<td>
									@if ($bid->URL)
										<a href="{{ $bid->URL }}" target="_blank" rel="noopener noreferrer"
											style="display:inline-block; padding:0.3rem 0.7rem; font-size:0.8rem; background:#2563eb; color:#fff; border-radius:4px; text-decoration:none; white-space:nowrap;">
											Open ↗
										</a>
									@else
										<span style="color:#9ca3af;">—</span>
									@endif
								</td>
								<td>
									<div class="action-buttons">
										<button type="button" class="secondary" onclick="openEditModal(
													{{ $bid->ID }},
													'{{ addslashes($bid->TITLE) }}',
													'{{ $bid->ENDDATE ?? '' }}',
													'{{ $bid->NAICSCODE ?? '' }}'
												)">
											✏️ Edit
										</button>
										<form action="{{ route('bids.destroy', ['bid' => $bid->ID]) }}" method="POST">
											@csrf
											@method('DELETE')
											<button type="submit"
												onclick="return confirm('Do you really want to delete this?')"
												class="contrast">
												🗑 Delete
											</button>
										</form>
									</div>
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="6" style="text-align:center; color:#6b7280;">No bids found.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			<!-- Info + Pagination -->
			<div class="pagination-bar">
				<div>
					Showing <span id="showingCount">{{ $bids->count() }}</span> of <span id="totalCount">{{ $bids->total() }}</span> entries
				</div>
				<div class="pagination">
					{{ $bids->links('pagination.bidurl') }}
				</div>
			</div>
		</section>

	<!-- Issues Tab -->
	<section id="panelIssues" class="card" style="display:none; border-top-left-radius:0;">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
			<span id="issuesCountLabel" style="color:#6b7280; font-size:0.85rem;">
				{{ $issueCount ?? 0 }} issue(s) recorded
			</span>
			@if (($issueCount ?? 0) > 0)
				<form id="clearIssuesForm" method="POST" action="{{ route('scrape.clearIssues') }}" onsubmit="return confirm('Clear all issues?')">
					@csrf
					@method('DELETE')
					<button type="submit" class="outline contrast" style="padding:0.4rem 0.8rem; font-size:0.8rem;">Clear All</button>
				</form>
			@endif
		</div>

		@if (($issueCount ?? 0) === 0)
			<p id="issuesEmptyState" style="color:#6b7280; text-align:center; padding:2rem 0;">No issues recorded.</p>
		@else
			<div id="issuesTableWrap" style="overflow-x:auto;">
				<table id="issuesTable" class="issues-table">
					<thead>
						<tr>
							<th>Level</th>
							<th>URL</th>
							<th>Message</th>
							<th>Date</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						@foreach ($scrapeLogs as $log)
							<tr class="issue-row" data-issue-id="{{ $log->id }}">
								<td>
									<span class="issue-badge issue-{{ $log->level }}">{{ $log->level }}</span>
								</td>
								<td style="font-size:0.85rem;">
									<a class="issues-url-link" href="{{ $log->url }}" target="_blank" rel="noopener">{{ $log->url }}</a>
								</td>
								<td style="font-size:0.85rem;">{{ $log->message }}</td>
								<td style="white-space:nowrap; font-size:0.85rem; color:#6b7280;">
									{{ $log->created_at->format('M d, Y h:i A') }}
								</td>
								<td>
									<form method="POST" action="{{ route('scrape.destroyIssue', $log) }}" class="issue-delete-form" style="margin:0;">
										@csrf
										@method('DELETE')
										<button type="submit" class="issue-delete-btn" aria-label="Delete issue" title="Delete issue">X</button>
									</form>
								</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		@endif
	</section>

	</main>

	<!-- Detail Modal -->
	<dialog id="detailModal" style="max-width:800px; width:90%; border:none; border-radius:12px; padding:0; box-shadow:0 8px 30px rgba(0,0,0,0.12);">
		<article style="margin:0; padding:2rem;">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem;">
				<h3 id="detail_title" style="margin:0; font-size:1.3rem; font-weight:600; color:#1f2937;"></h3>
				<button type="button" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; padding:0; line-height:1;" onclick="document.getElementById('detailModal').close()">&times;</button>
			</div>

			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.25rem;">
				<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:0.85rem;">
					<strong style="display:block; color:#374151; font-size:0.85rem; margin-bottom:0.25rem;">End Date</strong>
					<span id="detail_enddate" style="color:#111827;"></span>
				</div>
				<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:0.85rem;">
					<strong style="display:block; color:#374151; font-size:0.85rem; margin-bottom:0.25rem;">NAICS Code</strong>
					<span id="detail_naics" style="color:#111827;"></span>
				</div>
				<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:0.85rem;">
					<strong style="display:block; color:#374151; font-size:0.85rem; margin-bottom:0.25rem;">Scraped</strong>
					<span id="detail_created" style="color:#111827;"></span>
				</div>
			</div>

			<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:0.85rem; margin-bottom:1.25rem;">
				<strong style="display:block; color:#374151; font-size:0.85rem; margin-bottom:0.25rem;">URL</strong>
				<a id="detail_url" href="#" target="_blank" rel="noopener noreferrer" style="word-break:break-all;"></a>
			</div>

			<div>
				<strong style="display:block; color:#374151; font-size:0.95rem; margin-bottom:0.5rem;">Details</strong>
				<div id="detail_description" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:1rem; max-height:400px; overflow-y:auto; font-size:0.9rem; line-height:1.6;"></div>
			</div>

			<footer style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
				<button type="button" class="secondary" onclick="document.getElementById('detailModal').close()">Close</button>
			</footer>
		</article>
	</dialog>

	<!-- Edit Modal -->
	<dialog id="editModal">
		<article>
			<h3>Edit Bid</h3>
			<form id="editForm" method="POST">
				@csrf
				@method('PUT')

				<label for="edit_title">Title</label>
				<input type="text" id="edit_title" name="TITLE" required>

				<label for="edit_end_date">End Date</label>
				<input type="date" id="edit_end_date" name="ENDDATE">

				<label for="edit_naics_code">NAICS Code</label>
				<input type="text" id="edit_naics_code" name="NAICSCODE">

				<footer style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
					<button type="button" class="secondary"
						onclick="document.getElementById('editModal').close()">Cancel</button>
					<button type="submit" class="contrast">Update</button>
				</footer>
			</form>
		</article>
	</dialog>

	@php
		$bidsModalData = $bids->map(fn ($bid) => [
			'TITLE' => $bid->TITLE,
			'ENDDATE' => $bid->ENDDATE,
			'NAICSCODE' => $bid->NAICSCODE,
			'URL' => $bid->URL,
			'DESCRIPTION' => $bid->DESCRIPTION,
			'CREATED' => $bid->CREATED,
		])->values();
	@endphp
	<script>
		const bidsData = @json($bidsModalData);
		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

		document.addEventListener('click', function(e) {
			const link = e.target.closest('.bid-detail-link');
			if (link) {
				e.preventDefault();
				const idx = parseInt(link.dataset.bidIdx, 10);
				if (bidsData[idx]) openDetailModal(bidsData[idx]);
			}
		});

		function formatDate(dateStr) {
			if (!dateStr) return 'N/A';
			try {
				const d = new Date(dateStr);
				if (isNaN(d)) return dateStr;
				const months = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
				return months[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, '0') + ', ' + d.getFullYear();
			} catch (e) { return dateStr; }
		}

		function openDetailModal(bid) {
			const modal = document.getElementById('detailModal');
			document.getElementById('detail_title').textContent = bid.TITLE || 'Untitled Bid';

			document.getElementById('detail_enddate').textContent = bid.ENDDATE ? formatDate(bid.ENDDATE) : 'N/A';
			document.getElementById('detail_naics').textContent = bid.NAICSCODE || 'N/A';
			document.getElementById('detail_created').textContent = bid.CREATED ? formatDate(bid.CREATED) : 'N/A';

			const urlEl = document.getElementById('detail_url');
			urlEl.href = bid.URL || '#';
			urlEl.textContent = bid.URL || 'N/A';

			const descBox = document.getElementById('detail_description');
			const desc = (bid.DESCRIPTION || '').trim();
			if (!desc) {
				descBox.innerHTML = '<p style="margin:0; color:#6b7280;">No details available.</p>';
			} else {
				const lines = desc.split(/\r?\n+/).filter(l => l.trim());
				let html = '';
				lines.forEach((line, i) => {
					const bg = i % 2 === 0 ? 'background:#f3f4f6;' : '';
					const colonIdx = line.indexOf(':');
					if (colonIdx > 0 && colonIdx < 60) {
						const label = line.substring(0, colonIdx).trim();
						const value = line.substring(colonIdx + 1).trim();
						html += `<div style="display:grid; grid-template-columns:200px 1fr; gap:0.5rem; padding:0.5rem 0.65rem; border-radius:6px; ${bg} align-items:start;">
							<span style="font-weight:700; color:#1f2937; font-size:0.9rem;">${escHtml(label)}</span>
							<span style="color:#0f172a; white-space:pre-wrap;">${escHtml(value)}</span>
						</div>`;
					} else {
						html += `<div style="padding:0.5rem 0.65rem; border-radius:6px; ${bg}">
							<span style="color:#0f172a; white-space:pre-wrap;">${escHtml(line)}</span>
						</div>`;
					}
				});
				descBox.innerHTML = html;
			}
			modal.showModal();
		}

		function escHtml(str) {
			const div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}

		function openEditModal(ID, TITLE, ENDDATE, NAICSCODE) {
			const modal = document.getElementById('editModal');
			const form = document.getElementById('editForm');
			form.action = "{{ url('/bids') }}/" + ID;
			document.getElementById('edit_title').value = TITLE || '';
			document.getElementById('edit_end_date').value = ENDDATE ? ENDDATE.substring(0, 10) : '';
			document.getElementById('edit_naics_code').value = NAICSCODE || '';
			modal.showModal();
		}

		const table = document.getElementById("bidsTable");
		const rows = Array.from(table.querySelectorAll("tbody tr"));
		const filtersForm = document.getElementById("filtersForm");
		const searchInput = document.getElementById("searchInput");
		const filterDate = document.getElementById("filterDate");
		const filterNaics = document.getElementById("filterNaics");
		const showEntries = document.getElementById("showEntries");
		const showingCount = document.getElementById("showingCount");
		showingCount.textContent = rows.length;

		let searchSubmitTimer;

		searchInput.addEventListener("input", () => {
			clearTimeout(searchSubmitTimer);
			searchSubmitTimer = setTimeout(() => {
				filtersForm.requestSubmit();
			}, 300);
		});

		filterDate.addEventListener("change", () => filtersForm.requestSubmit());
		filterNaics.addEventListener("change", () => filtersForm.requestSubmit());
		showEntries.addEventListener("change", () => {
			const pageInput = filtersForm.querySelector('input[name="page"]');
			if (pageInput) {
				pageInput.remove();
			}
			filtersForm.requestSubmit();
		});

		function switchTab(tab) {
			document.getElementById('panelBids').style.display = tab === 'bids' ? '' : 'none';
			document.getElementById('panelIssues').style.display = tab === 'issues' ? '' : 'none';
			document.getElementById('tabBids').classList.toggle('tab-active', tab === 'bids');
			document.getElementById('tabIssues').classList.toggle('tab-active', tab === 'issues');
		}

		function updateIssueUiAfterDelete() {
			const remainingRows = document.querySelectorAll('#panelIssues .issue-row').length;
			const countLabel = document.getElementById('issuesCountLabel');
			const tabBadge = document.querySelector('#tabIssues .tab-badge');
			const tableWrap = document.getElementById('issuesTableWrap');
			const clearForm = document.getElementById('clearIssuesForm');
			let emptyState = document.getElementById('issuesEmptyState');

			if (countLabel) {
				countLabel.textContent = `${remainingRows} issue(s) recorded`;
			}

			if (tabBadge) {
				if (remainingRows > 0) {
					tabBadge.textContent = String(remainingRows);
				} else {
					tabBadge.remove();
				}
			}

			if (remainingRows === 0) {
				tableWrap?.remove();
				clearForm?.remove();

				if (!emptyState) {
					emptyState = document.createElement('p');
					emptyState.id = 'issuesEmptyState';
					emptyState.style.color = '#6b7280';
					emptyState.style.textAlign = 'center';
					emptyState.style.padding = '2rem 0';
					emptyState.textContent = 'No issues recorded.';
					document.getElementById('panelIssues')?.appendChild(emptyState);
				}
			}
		}

		document.addEventListener('submit', async function (event) {
			const form = event.target.closest('.issue-delete-form');
			if (!form) return;

			event.preventDefault();

			const row = form.closest('.issue-row');
			const button = form.querySelector('button[type="submit"]');
			if (button) button.disabled = true;

			try {
				const response = await fetch(form.action, {
					method: 'POST',
					headers: {
						'X-CSRF-TOKEN': csrfToken,
						'X-Requested-With': 'XMLHttpRequest',
						'Accept': 'application/json',
					},
					body: new FormData(form),
				});

				if (!response.ok) {
					throw new Error('Delete failed');
				}

				row?.remove();
				updateIssueUiAfterDelete();
			} catch (error) {
				if (button) button.disabled = false;
			}
		});

		function startScrapeAll() {
			const btn = document.getElementById('scrapeAllBtn');
			const panel = document.getElementById('scrapeProgress');
			const progressTitle = document.getElementById('progressTitle');
			const progressCounter = document.getElementById('progressCounter');
			const progressBar = document.getElementById('progressBar');
			const progressUrl = document.getElementById('progressUrl');
			const progressLog = document.getElementById('progressLog');

			btn.disabled = true;
			btn.textContent = 'Scraping...';
			panel.style.display = 'block';
			progressTitle.textContent = 'Initializing...';
			progressCounter.textContent = '';
			progressBar.style.width = '0%';
			progressUrl.textContent = '';
			progressLog.innerHTML = '';

			let total = 0;

			fetch("{{ route('bidurl.scrapeStream') }}", {
				method: 'GET',
				headers: { 'Accept': 'text/event-stream' },
			}).then(response => {
				const reader = response.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';

				function read() {
					reader.read().then(({ done, value }) => {
						if (done) {
							btn.disabled = false;
							btn.textContent = 'Scrape All';
							return;
						}
						buffer += decoder.decode(value, { stream: true });
						const lines = buffer.split('\n');
						buffer = lines.pop();

						lines.forEach(line => {
							if (!line.startsWith('data: ')) return;
							try {
								const ev = JSON.parse(line.substring(6));
								handleScrapeEvent(ev);
							} catch (e) {}
						});
						read();
					});
				}
				read();
			}).catch(err => {
				progressTitle.textContent = 'Error: ' + err.message;
				progressBar.style.background = '#dc2626';
				btn.disabled = false;
				btn.textContent = 'Scrape All';
			});

			function handleScrapeEvent(ev) {
				switch (ev.type) {
					case 'start':
						total = ev.total;
						progressTitle.textContent = 'Scraping ' + total + ' URL(s)...';
						break;

					case 'processing':
						progressCounter.textContent = ev.index + ' / ' + total;
						progressBar.style.width = Math.round((ev.index / total) * 100) + '%';
						progressUrl.innerHTML = '<span style="color:#2563eb;">Processing:</span> ' + escapeHtml(ev.url);
						break;

					case 'status':
						progressUrl.innerHTML = '<span style="color:#2563eb;">Processing:</span> ' + escapeHtml(ev.step);
						break;

					case 'skip':
						progressCounter.textContent = ev.index + ' / ' + total;
						progressBar.style.width = Math.round((ev.index / total) * 100) + '%';
						addLogLine('Skipped: ' + ev.url + ' (' + ev.reason + ')', '#9ca3af');
						break;

					case 'done_url':
						addLogLine(ev.url + ' — ' + ev.saved + ' saved, ' + ev.duplicates + ' duplicates' + (ev.message ? ' (' + ev.message + ')' : ''), ev.saved > 0 ? '#16a34a' : '#6b7280');
						break;

					case 'error':
						addLogLine('Error: ' + ev.url + ' — ' + ev.message, '#dc2626');
						break;

					case 'complete':
						progressBar.style.width = '100%';
						progressBar.style.background = '#16a34a';
						let msg = ev.total_saved + ' new bid(s) saved.';
						if (ev.total_duplicates > 0) msg += ' ' + ev.total_duplicates + ' duplicate(s).';
						if (ev.total_skipped > 0) msg += ' ' + ev.total_skipped + ' already scraped today.';
						if (ev.total_issues > 0) msg += ' ' + ev.total_issues + ' issue(s).';
						progressTitle.textContent = 'Complete! ' + msg;
						progressUrl.innerHTML = ev.total_issues > 0
							? '<a href="javascript:void(0)" onclick="switchTab(\'issues\')" style="color:#dc2626;">View Issues</a>'
							: '';
						btn.disabled = false;
						btn.textContent = 'Scrape All';
						if (ev.total_saved > 0) {
							setTimeout(() => location.reload(), 2000);
						}
						break;
				}
			}

			function addLogLine(text, color) {
				const div = document.createElement('div');
				div.style.color = color || '#6b7280';
				div.style.padding = '1px 0';
				div.textContent = text;
				progressLog.appendChild(div);
				progressLog.scrollTop = progressLog.scrollHeight;
			}

			function escapeHtml(s) {
				const d = document.createElement('div');
				d.textContent = s;
				return d.innerHTML;
			}
		}
	</script>
</body>

</html>




