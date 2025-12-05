<!doctype html>
<html>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Import Bid URLs</title>
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
	</style>
</head>

<body>
	<main class="container">
		<nav>
			<h1>Import Bid URLs</h1>
			<div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
				<a href="{{ route('bidurl.index') }}" class="secondary">View URLs</a>
				<a href="{{ route('bids.index') }}">Back to Bids</a>
			</div>
		</nav>

		@if (session('success'))
			<div class="alert success">{{ session('success') }}</div>
		@endif

		@if ($errors->any())
			<div class="alert error">
				<ul style="margin:0; padding-left:1.25rem;">
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		@endif

		<section class="card">
			<h2 style="margin-top:0;">Upload .txt or .csv</h2>
			<p style="color:#555;">Each line should be pipe separated with at least 11 fields: URL|name|start_time|end_time|weight|user_id|check_changes|visit_required|checksum|valid|third_party_url_id</p>
			<form method="POST" action="{{ route('bidurl.store') }}" enctype="multipart/form-data">
				@csrf
				<label for="file">Choose file</label>
				<input type="file" id="file" name="file" accept=".txt,.csv" required>
				<button type="submit" style="margin-top:0.75rem;">Upload &amp; Import</button>
			</form>
		</section>
	</main>
</body>

</html>
