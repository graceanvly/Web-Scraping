<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Login</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700&display=swap">
	<style>
		:root {
			--ink: #0f172a;
			--muted: #475569;
			--primary: #2563eb;
			--primary-strong: #1d4ed8;
			--surface: #ffffff;
			--stroke: #e2e8f0;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			background:
				radial-gradient(circle at 15% 20%, rgba(59, 130, 246, 0.12), transparent 32%),
				radial-gradient(circle at 85% 10%, rgba(94, 234, 212, 0.18), transparent 25%),
				linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
			font-family: 'Manrope', 'Segoe UI', system-ui, -apple-system, sans-serif;
			color: var(--ink);
			padding: 32px 18px;
		}

		main {
			width: min(500px, 96vw);
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.form-card {
			width: 100%;
			background: var(--surface);
			padding: 30px;
			border-radius: 18px;
			box-shadow: 0 24px 60px -30px rgba(15, 23, 42, 0.3);
			border: 1px solid var(--stroke);
		}

		.form-card h2 {
			margin: 0 0 6px;
			font-size: 26px;
			letter-spacing: -0.01em;
		}

		.form-card p {
			margin: 0 0 22px;
			color: var(--muted);
		}

		.form-grid {
			display: grid;
			gap: 16px;
		}

		.label-row {
			display: flex;
			align-items: center;
			justify-content: space-between;
			color: var(--muted);
			font-weight: 600;
			font-size: 14px;
		}

		.input-wrapper {
			display: grid;
			gap: 6px;
		}

		input[type="email"],
		input[type="password"] {
			width: 100%;
			padding: 12px 14px;
			border-radius: 12px;
			border: 1px solid var(--stroke);
			background: #f8fafc;
			transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
		}

		input[type="email"]:focus,
		input[type="password"]:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.14);
			background: #ffffff;
		}

		.error {
			color: #dc2626;
			font-size: 13px;
		}

		.actions {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			flex-wrap: wrap;
			color: var(--muted);
			font-size: 14px;
		}

		.actions label {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-weight: 600;
			cursor: pointer;
		}

		button[type="submit"] {
			width: 100%;
			border: none;
			background: linear-gradient(135deg, var(--primary), var(--primary-strong));
			color: #ffffff;
			font-weight: 700;
			padding: 14px;
			border-radius: 12px;
			cursor: pointer;
			box-shadow: 0 16px 35px -18px rgba(37, 99, 235, 0.65);
			transition: transform 0.15s ease, box-shadow 0.2s ease;
		}

		button[type="submit"]:hover {
			transform: translateY(-1px);
			box-shadow: 0 18px 40px -18px rgba(37, 99, 235, 0.75);
		}

		button[type="submit"]:active {
			transform: translateY(0);
			box-shadow: 0 16px 35px -20px rgba(37, 99, 235, 0.7);
		}

		.note {
			margin-top: 12px;
			color: var(--muted);
			font-size: 13px;
			text-align: center;
		}

		@media (max-width: 720px) {
			body {
				padding: 22px 16px;
			}
		}
	</style>
</head>
<body>
	<main>
		<section class="form-card">
			<h2>Sign in</h2>
			<p></p>

			<form method="POST" action="{{ route('login.attempt') }}" class="form-grid">
				@csrf

				<div class="input-wrapper">
					<div class="label-row">
						<label for="email">Email</label>
						<span aria-hidden="true">@</span>
					</div>
					<input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
					@error('email')
						<div class="error">{{ $message }}</div>
					@enderror
				</div>

				<div class="input-wrapper">
					<div class="label-row">
						<label for="password">Password</label>
						<span aria-hidden="true">&bull;&bull;&bull;</span>
					</div>
					<input id="password" type="password" name="password" required>
					@error('password')
						<div class="error">{{ $message }}</div>
					@enderror
				</div>

				<div class="actions">
					<label>
						<input type="checkbox" name="remember" value="1"> Remember me
					</label>
				</div>

				<button type="submit">Log In</button>
				
			</form>
		</section>
	</main>
</body>
</html>
