<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Bid Details</title>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	</head>
	<body>
		<main class="container">
			@if (session('success'))
				<div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
					{{ session('success') }}
				</div>
			@endif
			<a href="{{ route('bids.index') }}">← Back</a>
			<h1>{{ $bid->title ?? 'Untitled' }}</h1>
			<p><strong>URL:</strong> <a href="{{ $bid->url }}" target="_blank" rel="noreferrer">{{ $bid->url }}</a></p>
			<p><strong>End Date:</strong> {{ optional($bid->end_date)->toDateTimeString() ?? '—' }}</p>
			<p><strong>NAICS:</strong> {{ $bid->naics_code ?? '—' }}</p>
			<h3>Other Data</h3>
			<pre>{{ json_encode($bid->other_data, JSON_PRETTY_PRINT) }}</pre>
			<h3>Extracted JSON</h3>
			<pre>{{ json_encode($bid->extracted_json, JSON_PRETTY_PRINT) }}</pre>
		</main>
	</body>
</html>
