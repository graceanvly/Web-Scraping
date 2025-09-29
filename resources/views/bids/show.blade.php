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
			.card h1, .card h2 {
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
			.code-block {
				background: #1e293b;
				color: #f8fafc;
				padding: 1rem;
				border-radius: 8px;
				overflow-x: auto;
				font-family: ui-monospace, monospace;
				font-size: 0.9rem;
				line-height: 1.5;
				margin-top: 1rem;
			}
			.alert-success {
				background: #d1fae5;
				color: #065f46;
				padding: 1rem;
				border-radius: 8px;
				margin-bottom: 2rem;
				border: 1px solid #10b981;
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
				<h1>{{ $bid->title ?? 'Untitled Bid' }}</h1>
				<div class="meta-grid">
					<div>
						<strong>End Date</strong>
						<span>{{ optional($bid->end_date)->toDayDateTimeString() ?? '—' }}</span>
					</div>
					<div>
						<strong>NAICS</strong>
						<span>{{ $bid->naics_code ?? '—' }}</span>
					</div>
					<div style="grid-column: 1 / -1;">
						<strong>URL</strong>
						<a href="{{ $bid->url }}" target="_blank" rel="noreferrer">{{ $bid->url }}</a>
					</div>
				</div>
			</section>


			<section class="card">
				<h2>Extracted JSON</h2>
				<div class="code-block">
					<pre>{{ json_encode($bid->extracted_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
				</div>
			</section>
		</main>
	</body>
</html>
