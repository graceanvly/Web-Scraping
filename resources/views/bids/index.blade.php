<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Bid Scraper</title>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	</head>
	<body>
		<main class="container">
			<nav style="display:flex;justify-content:space-between;align-items:center;gap:1rem">
				<h1>Bid Scraper</h1>
				<form method="POST" action="{{ route('logout') }}">
					@csrf
					<button type="submit" class="secondary">Logout</button>
				</form>
			</nav>
			<form method="POST" action="{{ route('bids.store') }}">
				@csrf
				<label for="url">Bidding URL</label>
				<input type="url" id="url" name="url" value="{{ old('url') }}" placeholder="https://..." required>
				@error('url')
					<small style="color:red">{{ $message }}</small>
				@enderror
				<button type="submit">Scrape</button>
			</form>

			<h2>Recent Bids</h2>
			<table>
				<thead>
					<tr>
						<th>Title</th>
						<th>End Date</th>
						<th>NAICS</th>
						<th>URL</th>
					</tr>
				</thead>
				<tbody>
				@foreach ($bids as $bid)
					<tr>
						<td><a href="{{ route('bids.show', $bid) }}">{{ $bid->title ?? '—' }}</a></td>
						<td>{{ optional($bid->end_date)->toDateTimeString() ?? '—' }}</td>
						<td>{{ $bid->naics_code ?? '—' }}</td>
						<td><a href="{{ $bid->url }}" target="_blank" rel="noreferrer">link</a></td>
					</tr>
				@endforeach
				</tbody>
			</table>
		</main>
	</body>
</html>
