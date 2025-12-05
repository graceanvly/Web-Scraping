<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Bid URL Details</title>
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
			gap: 0.5rem;
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

		.details li {
			margin-bottom: 0.35rem;
		}
	</style>
</head>

<body>
	<main class="container">
		<nav>
			<h1>Bid URL Details</h1>
			<div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
				<a href="{{ route('bidurl.index') }}" class="secondary">Back to list</a>
				<a href="{{ route('bids.index') }}">Back to Bids</a>
			</div>
		</nav>

		<section class="card">
			<ul class="details">
				<li><strong>URL:</strong> <a href="{{ $bidUrl->url }}" target="_blank" rel="noreferrer">{{ $bidUrl->url }}</a></li>
				<li><strong>Name:</strong> {{ $bidUrl->name ?? '—' }}</li>
				<li><strong>Valid:</strong> {{ $bidUrl->valid ? 'Yes' : 'No' }}</li>
				<li><strong>Start Time:</strong> {{ $bidUrl->start_time ?? '—' }}</li>
				<li><strong>End Time:</strong> {{ $bidUrl->end_time ?? '—' }}</li>
				<li><strong>Weight:</strong> {{ $bidUrl->weight ?? '—' }}</li>
				<li><strong>User ID:</strong> {{ $bidUrl->user_id ?? '—' }}</li>
				<li><strong>Check Changes:</strong> {{ $bidUrl->check_changes ?? '—' }}</li>
				<li><strong>Visit Required:</strong> {{ $bidUrl->visit_required ?? '—' }}</li>
				<li><strong>Checksum:</strong> {{ $bidUrl->checksum ?? '—' }}</li>
				<li><strong>Third Party URL ID:</strong> {{ $bidUrl->third_party_url_id ?? '—' }}</li>
				<li><strong>Created:</strong> {{ $bidUrl->created_at ?? '—' }}</li>
				<li><strong>Updated:</strong> {{ $bidUrl->updated_at ?? '—' }}</li>
			</ul>
		</section>
	</main>
</body>

</html>
