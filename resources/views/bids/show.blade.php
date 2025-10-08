<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Bid Details</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		body {
			background: #f5f7fa;
		}

		.container {
			max-width: 900px;
		}

		.back-link {
			display: inline-block;
			margin-bottom: 2rem;
			color: var(--primary);
			text-decoration: none;
			font-weight: 500;
		}

		.card {
			background: #fff;
			padding: 2rem;
			border-radius: 10px;
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
			margin-bottom: 2rem;
		}

		.card h1,
		.card h2 {
			margin-top: 0;
			font-weight: 600;
		}

		.meta-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
			gap: 1.5rem;
			margin-top: 1.5rem;
		}

		.meta-grid div {
			background: #f9fafb;
			border: 1px solid #e5e7eb;
			border-radius: 6px;
			padding: 1rem;
		}

		.meta-grid strong {
			display: block;
			margin-bottom: 0.25rem;
			color: #374151;
		}

		.sub-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1rem;
			margin-top: 1rem;
		}

		.sub-grid div {
			background: #f3f4f6;
			border: 1px solid #e5e7eb;
			border-radius: 6px;
			padding: 0.75rem;
		}
	</style>
</head>

<body>
	<main class="container">
		@if (session('success'))
			<div class="alert-success">
				{{ session('success') }}
			</div>
		@endif

		<a href="{{ route('bids.index') }}" class="back-link">← Back to All Bids</a>

		<section class="card">
			<h1>{{ $bid->TITLE ?? 'Untitled Bid' }}</h1>
			<div class="meta-grid">
				<div style="grid-column: 1 / -1;">
					<strong>URL</strong>
					<a href="{{ $bid->URL }}" target="_blank" rel="noreferrer">{{ $bid->URL }}</a>
				</div>
			</div>
		</section>

		<section class="card">
			<h2>Extracted Data</h2>

			<div class="meta-grid">
				<div>
					<strong>Title</strong>
					<span>{{ $bid->TITLE ?? '—' }}</span>
				</div>
				<div>
					<strong>End Date</strong>
					<span>
						{{ $bid->ENDDATE ? \Carbon\Carbon::parse($bid->ENDDATE)->format('M. d, Y') : '—' }}
					</span>
				</div>
				<div>
					<strong>Description</strong>
					<span>{{ $bid->DESCRIPTION ?? '—' }}</span>
				</div>
				<div>
					<strong>NAICS</strong>
					<span>{{ $bid->NAICSCODE ?? '—' }}</span>
				</div>
			</div>
		</section>

	</main>
</body>

</html>