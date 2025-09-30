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
			/* full viewport height */
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
		}

		thead {
			background: #f0f2f5;
			font-weight: 600;
			color: #1d4ed8;
			/* blue headers */
		}

		tbody tr:nth-child(even) {
			background: #fafafa;
		}

		tbody tr:hover {
			background: #f1f5f9;
		}

		/* Title column links */
		td:first-child a {
			color: #337fe4ff;
			font-weight: 600;
			text-decoration: none;
		}

		td:first-child a:hover {
			text-decoration: underline;
			color: #1e40af;
		}

		/* Keep "Open" links subtle */
		td a:not(:first-child) {
			color: var(--primary);
			font-weight: normal;
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

				<!-- Right: Scrape All -->
				<form method="POST" action="{{ route('bidurl.scrapeAll') }}">
					@csrf
					<button type="submit" class="contrast"
						style="padding:0.6rem 1.2rem; font-size:0.95rem; white-space:nowrap;">
						🚀 Scrape All
					</button>
				</form>

				<!-- Left: Add URL -->
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
			<div style="overflow-x:auto;">
				<table role="grid">
					<thead>
						<tr>
							<th>Title</th>
							<th>End Date</th>
							<th>NAICS</th>
							<th>URL</th>
						</tr>
					</thead>
					<tbody>
						@forelse ($bids as $bid)
							<tr>
								<td><a href="{{ route('bids.show', $bid) }}">{{ $bid->title ?? '—' }}</a></td>
								<td>{{ $bid->end_date ? $bid->end_date->format('M. d, Y h:i a') : '—' }}</td>
								<td>{{ $bid->naics_code ?? '—' }}</td>
								<td><a href="{{ $bid->url }}" target="_blank" rel="noreferrer">Open</a></td>
							</tr>
						@empty
							<tr>
								<td colspan="4" style="text-align:center; color:#6b7280;">No bids found.</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</section>
	</main>
</body>

</html>