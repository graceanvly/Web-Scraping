<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
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
		td.url-cell { max-width: 300px; word-break: break-all; font-size: 0.85rem; }
		td.msg-cell { max-width: 400px; word-break: break-word; font-size: 0.85rem; }
		.pagination-links { margin-top: 1rem; }
		.pagination-links nav { margin-bottom: 0; }

		@media (max-width: 768px) {
			table { font-size: 0.75rem; }
			th, td { padding: 0.4rem 0.5rem; }
			td.url-cell { max-width: 140px; }
			td.msg-cell { max-width: 180px; }
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
				<p style="color:#6b7280; text-align:center; padding:2rem 0;">No issues recorded.</p>
			@else
				<p style="color:#6b7280; font-size:0.85rem; margin-bottom:0.75rem;">
					Showing {{ $logs->firstItem() }}-{{ $logs->lastItem() }} of {{ $logs->total() }} issue(s)
				</p>
				<div style="overflow-x:auto;">
					<table>
						<thead>
							<tr>
								<th>Level</th>
								<th>URL</th>
								<th>Message</th>
								<th>Date</th>
							</tr>
						</thead>
						<tbody>
							@foreach ($logs as $log)
								<tr>
									<td>
										<span class="badge badge-{{ $log->level }}">{{ $log->level }}</span>
									</td>
									<td class="url-cell">
										<a href="{{ $log->url }}" target="_blank" rel="noopener" style="color:#2563eb;">{{ $log->url }}</a>
									</td>
									<td class="msg-cell">{{ $log->message }}</td>
									<td style="white-space:nowrap; font-size:0.85rem; color:#6b7280;">
										{{ $log->created_at->format('M d, Y h:i A') }}
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
</body>

</html>
