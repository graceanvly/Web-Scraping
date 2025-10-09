<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Bid Details</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<style>
		html,
		body {
			background: linear-gradient(135deg, #f5f7fa 0%, #e4ebf5 100%);
			color: #111827;
			font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			line-height: 1.6;
		}

		main.container {
			max-width: 900px;
			margin: 3rem auto;
		}

		a.back-link {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			color: #2563eb;
			text-decoration: none;
			font-weight: 500;
			margin-bottom: 1.5rem;
			transition: color 0.2s;
		}

		a.back-link:hover {
			color: #1d4ed8;
		}

		.card {
			background: #fff;
			padding: 2rem;
			border-radius: 12px;
			box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
			margin-bottom: 2rem;
		}

		.card h1,
		.card h2 {
			margin: 0 0 1rem;
			font-weight: 600;
			color: #1f2937;
		}

		.meta-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
			gap: 1.25rem;
			margin-top: 1rem;
		}

		.meta-item {
			background: #f9fafb;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 1rem;
		}

		.meta-item strong {
			display: block;
			color: #374151;
			margin-bottom: 0.4rem;
			font-size: 0.95rem;
		}

		.meta-item span {
			color: #111827;
			font-size: 0.95rem;
		}

		.description-box {
			background: #f9fafb;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 1.25rem;
			margin-top: 0.5rem;
			max-height: 400px;
			overflow-y: auto;
			color: #111827;
			font-size: 0.95rem;
		}

		.description-box p {
			margin: 0.5rem 0; /* ✅ tighter paragraph spacing */
			line-height: 1.5;
		}

		.description-box::-webkit-scrollbar {
			width: 8px;
		}

		.description-box::-webkit-scrollbar-thumb {
			background-color: #cbd5e1;
			border-radius: 8px;
		}

		.alert-success {
			background: #dcfce7;
			border: 1px solid #86efac;
			color: #166534;
			padding: 1rem;
			border-radius: 8px;
			margin-bottom: 1.5rem;
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

			<div class="meta-item" style="margin-top: 1rem;">
				<strong>URL</strong>
				<a href="{{ $bid->URL }}" target="_blank" rel="noopener noreferrer">{{ $bid->URL }}</a>
			</div>
		</section>

		<section class="card">
			<h2>Extracted Data</h2>

			<div class="meta-grid">
				<div class="meta-item">
					<strong>Title</strong>
					<span>{{ $bid->TITLE ?? '—' }}</span>
				</div>

				<div class="meta-item">
					<strong>End Date</strong>
					<span>
						{{ $bid->ENDDATE ? \Carbon\Carbon::parse($bid->ENDDATE)->format('M. d, Y') : '—' }}
					</span>
				</div>

				<div class="meta-item">
					<strong>NAICS</strong>
					<span>{{ $bid->NAICSCODE ?? '—' }}</span>
				</div>
			</div>

			<div style="margin-top: 1.5rem;">
				<strong>Description</strong>
				<div class="description-box">
					{!! collect(preg_split('/\r?\n{2,}/', trim($bid->DESCRIPTION ?? '')))
						->filter()
						->map(fn($p) => '<p>' . e(trim($p)) . '</p>')
						->implode('')
					!!}
				</div>
			</div>
		</section>

	</main>
</body>

</html>
