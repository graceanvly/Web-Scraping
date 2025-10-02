<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
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
			/* End Date */
			th:nth-child(4),
			td:nth-child(4)

			/* URL */
				{
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
			<form method="POST" action="{{ route('logout') }}">
				@csrf
				<button type="submit" class="secondary">Logout</button>
			</form>
		</nav>

		<!-- Actions -->
		<section class="card">
			<div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center; justify-content:space-between;">
				<!-- Scrape All -->
				<form method="POST" action="{{ route('bidurl.scrapeAll') }}">
					@csrf
					<button type="submit" class="contrast"
						style="padding:0.6rem 1.2rem; font-size:0.95rem; white-space:nowrap;">
						🚀 Scrape All
					</button>
				</form>

				<!-- Add URL -->
				<form method="POST" action="{{ route('bids.store') }}"
					style="display:flex; gap:0.5rem; flex:1; max-width:600px;">
					@csrf
					<input type="url" id="url" name="url" value="{{ old('url') }}" placeholder="Enter bidding URL…"
						required style="flex:1; min-width:80px; padding:0.6rem 0.75rem; font-size:0.95rem;">
					<button type="submit"
						style="flex:0; width:auto; padding:0.45rem 0.9rem; font-size:0.85rem; white-space:nowrap;">
						Scrape
					</button>
				</form>
			</div>
			@error('url')
				<small style="color:red; display:block; margin-top:0.5rem;">{{ $message }}</small>
			@enderror
		</section>

		<!-- Recent Bids -->
		<section class="card">
			<h2 style="margin-top:0;">Recent Bids</h2>

			<!-- Toolbar -->
			<div class="table-toolbar">
				<div class="left-controls">
					<label style="display:flex; align-items:center; gap:0.3rem;">
						Show
						<select id="showEntries">
							<option value="5">5</option>
							<option value="10" selected>10</option>
							<option value="25">25</option>
							<option value="50">50</option>
						</select>
						entries
					</label>
				</div>
				<div class="right-controls">
					<input type="text" id="searchInput" placeholder="Search…" />
					<select id="filterNaics">
						<option value="">NAICS</option>
						@foreach ($bids->pluck('naics_code')->unique() as $code)
							@if ($code)
								<option value="{{ $code }}">{{ $code }}</option>
							@endif
						@endforeach
					</select>
				</div>
			</div>

			<div style="overflow-x:auto;">
				<table role="grid" id="bidsTable">
					<thead>
						<tr>
							<th>Title</th>
							<th>End Date</th>
							<th>NAICS</th>
							<th>URL</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($bids as $bid)
							<tr>
								<td title="{{ $bid->title }}">
									<a href="{{ route('bids.show', $bid) }}">{{ $bid->title ?? '—' }}</a>
								</td>
								<td>{{ $bid->end_date ? $bid->end_date->format('M. d, Y h:i a') : '—' }}</td>
								<td title="{{ $bid->naics_code }}">{{ $bid->naics_code ?? '—' }}</td>
								<td><a href="{{ $bid->url }}" target="_blank" rel="noreferrer">Open</a></td>
								<td>
									<div class="action-buttons">
										<button type="button" class="secondary"
											onclick="openEditModal({{ $bid->id }}, '{{ addslashes($bid->title) }}', '{{ $bid->end_date ? $bid->end_date->format('Y-m-d\TH:i') : '' }}', '{{ $bid->naics_code }}')">
											✏️ Edit
										</button>
										<form action="{{ route('bids.destroy', $bid) }}" method="POST">
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
								<td colspan="5" style="text-align:center; color:#6b7280;">No bids found.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			<!-- Info + Pagination -->
			<div
				style="margin-top:0.75rem; font-size:0.9rem; color:#555; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; ;">
				<div>
					Showing <span id="showingCount">0</span> of <span id="totalCount">{{ count($bids) }}</span> entries
				</div>
				<div style="display:flex; gap:0.5rem;">
					<button id="prevPage" disabled>⬅ Back</button>
					<button id="nextPage" disabled>Next ➡</button>
				</div>
			</div>
		</section>
	</main>

	<!-- Edit Modal -->
	<dialog id="editModal">
		<article>
			<h3>Edit Bid</h3>
			<form id="editForm" method="POST">
				@csrf
				@method('PUT')

				<label for="edit_title">Title</label>
				<input type="text" id="edit_title" name="title" required>

				<label for="edit_end_date">End Date</label>
				<input type="datetime-local" id="edit_end_date" name="end_date">

				<label for="edit_naics_code">NAICS Code</label>
				<input type="text" id="edit_naics_code" name="naics_code">

				<footer>
					<button type="button" onclick="document.getElementById('editModal').close()">Cancel</button>
					<button type="submit" class="contrast">Update</button>
				</footer>
			</form>
		</article>
	</dialog>

	<script>
		// Modal
		function openEditModal(id, title, endDate, naics) {
			const modal = document.getElementById('editModal');
			const form = document.getElementById('editForm');

			form.action = `/bids/${id}`;
			document.getElementById('edit_title').value = title || '';
			document.getElementById('edit_end_date').value = endDate || '';
			document.getElementById('edit_naics_code').value = naics || '';

			modal.showModal();
		}

		// Filtering + Search + Show entries + Pagination
		const table = document.getElementById("bidsTable");
		const rows = Array.from(table.querySelectorAll("tbody tr"));
		const searchInput = document.getElementById("searchInput");
		const filterNaics = document.getElementById("filterNaics");
		const showEntries = document.getElementById("showEntries");
		const showingCount = document.getElementById("showingCount");
		const totalCount = document.getElementById("totalCount");
		const prevPageBtn = document.getElementById("prevPage");
		const nextPageBtn = document.getElementById("nextPage");

		let currentPage = 1;
		let filteredRows = [];

		function applyFilters() {
			let search = searchInput.value.toLowerCase();
			let filter = filterNaics.value;

			filteredRows = rows.filter(row => {
				let title = row.cells[0]?.innerText.toLowerCase() || "";
				let naics = row.cells[2]?.innerText.toLowerCase() || "";
				let matchesSearch = !search || title.includes(search) || naics.includes(search);
				let matchesFilter = !filter || naics === filter.toLowerCase();
				return matchesSearch && matchesFilter;
			});

			totalCount.textContent = filteredRows.length;
			currentPage = 1; // reset to first page
			renderTable();
		}

		function renderTable() {
			let limit = parseInt(showEntries.value);
			let start = (currentPage - 1) * limit;
			let end = start + limit;

			rows.forEach(row => row.style.display = "none"); // hide all
			filteredRows.slice(start, end).forEach(row => row.style.display = ""); // show only current page

			showingCount.textContent = filteredRows.slice(start, end).length;

			prevPageBtn.disabled = currentPage === 1;
			nextPageBtn.disabled = end >= filteredRows.length;
		}

		// Pagination controls
		prevPageBtn.addEventListener("click", () => {
			if (currentPage > 1) {
				currentPage--;
				renderTable();
			}
		});

		nextPageBtn.addEventListener("click", () => {
			let limit = parseInt(showEntries.value);
			if (currentPage * limit < filteredRows.length) {
				currentPage++;
				renderTable();
			}
		});

		// Event listeners
		searchInput.addEventListener("input", applyFilters);
		filterNaics.addEventListener("change", applyFilters);
		showEntries.addEventListener("change", applyFilters);

		applyFilters(); // initial
	</script>
</body>

</html>