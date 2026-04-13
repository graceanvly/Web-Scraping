<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>Scrape Issues - Bid Scraper</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		html, body {
			margin: 0; padding: 0; min-height: 100vh;
			background: linear-gradient(135deg, #f5f7fa 0%, #e4ebf5 100%);
			font-family: system-ui, sans-serif;
		}
		nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; }
		h1 { margin: 0; font-size: 1.8rem; font-weight: 600; }
		.card {
			background: #fff; padding: 1.5rem; border-radius: 10px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 2rem;
		}
		table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
		th, td { padding: 0.75rem 1rem; text-align: left; vertical-align: top; }
		.issues-table { table-layout: fixed; }
		.issues-table th, .issues-table td { overflow-wrap: anywhere; word-break: break-word; }
		.issues-table th:nth-child(1), .issues-table td:nth-child(1) { width: 120px; }
		.issues-table th:nth-child(2), .issues-table td:nth-child(2) { width: 30%; }
		.issues-table th:nth-child(3), .issues-table td:nth-child(3) { width: 40%; }
		.issues-table th:nth-child(4), .issues-table td:nth-child(4) { width: 190px; }
		.issues-table th:nth-child(5), .issues-table td:nth-child(5) { width: 110px; }
		thead { background: #f0f2f5; font-weight: 600; color: #1d4ed8; }
		tbody tr:nth-child(even) { background: #fafafa; }
		tbody tr:hover { background: #f1f5f9; }
		.alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid transparent; font-size: 0.95rem; }
		.alert.success { background: #ecfdf3; border-color: #bbf7d0; color: #166534; }
		.badge {
			display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px;
			font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em;
		}
		.badge-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
		.badge-warning { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
		.badge-success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
		td.url-cell { font-size: 0.85rem; white-space: normal; }
		td.msg-cell { font-size: 0.85rem; white-space: normal; }
		.url-link { color:#2563eb; display:inline-block; max-width:100%; white-space:normal; overflow-wrap:anywhere; word-break:break-word; }
		.pagination-links { margin-top: 1rem; }
		.pagination-links nav { margin-bottom: 0; }
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

		@media (max-width: 768px) {
			table { font-size: 0.75rem; }
			th, td { padding: 0.4rem 0.5rem; }
			.issues-table th:nth-child(1), .issues-table td:nth-child(1) { width: 96px; }
			.issues-table th:nth-child(4), .issues-table td:nth-child(4) { width: 150px; }
			.issues-table th:nth-child(5), .issues-table td:nth-child(5) { width: 92px; }
		}
	</style>
</head>

<body>
	<main class="container">
		<nav>
			<h1>Scrape Issues</h1>
			<div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
				<a href="{{ route('bids.index') }}" role="button" class="secondary" style="padding:0.5rem 1rem; font-size:0.9rem;">Back to Bids</a>
				@if ($logs->total() > 0)
					<form method="POST" action="{{ route('scrape.clearIssues') }}" onsubmit="return confirm('Clear all issues?')">
						@csrf
						@method('DELETE')
						<button type="submit" class="outline contrast" style="padding:0.5rem 1rem; font-size:0.9rem;">Clear All</button>
					</form>
				@endif
			</div>
		</nav>

		@if (session('success'))
			<div class="alert success">{{ session('success') }}</div>
		@endif

		<section class="card">
			@if ($logs->total() === 0)
				<p id="issuesEmptyState" style="color:#6b7280; text-align:center; padding:2rem 0;">No issues recorded.</p>
			@else
				<p id="issuesCountLabel" style="color:#6b7280; font-size:0.85rem; margin-bottom:0.75rem;">
					Showing {{ $logs->firstItem() }}-{{ $logs->lastItem() }} of {{ $logs->total() }} issue(s)
				</p>
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
							@foreach ($logs as $log)
								<tr class="issue-row" data-issue-id="{{ $log->id }}">
									<td>
										<span class="badge badge-{{ $log->level }}">{{ $log->level }}</span>
									</td>
									<td class="url-cell">
										<a class="url-link" href="{{ $log->url }}" target="_blank" rel="noopener">{{ $log->url }}</a>
									</td>
									<td class="msg-cell">{{ $log->message }}</td>
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

				<div class="pagination-links">
					{{ $logs->links() }}
				</div>
			@endif
		</section>
	</main>
	<script>
		const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

		function updateIssueUiAfterDelete() {
			const remainingRows = document.querySelectorAll('.issue-row').length;
			const countLabel = document.getElementById('issuesCountLabel');
			const tableWrap = document.getElementById('issuesTableWrap');
			let emptyState = document.getElementById('issuesEmptyState');

			if (countLabel) {
				countLabel.textContent = `${remainingRows} issue(s)`;
			}

			if (remainingRows === 0) {
				tableWrap?.remove();
				countLabel?.remove();

				if (!emptyState) {
					emptyState = document.createElement('p');
					emptyState.id = 'issuesEmptyState';
					emptyState.style.color = '#6b7280';
					emptyState.style.textAlign = 'center';
					emptyState.style.padding = '2rem 0';
					emptyState.textContent = 'No issues recorded.';
					document.querySelector('.card')?.appendChild(emptyState);
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
	</script>
</body>

</html>
