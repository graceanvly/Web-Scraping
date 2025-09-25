<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Login</title>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
	</head>
	<body>
		<main class="container">
			<h1>Sign in</h1>
			<form method="POST" action="{{ route('login.attempt') }}">
				@csrf
				<label for="email">Email</label>
				<input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
				@error('email')
					<small style="color:red">{{ $message }}</small>
				@enderror

				<label for="password">Password</label>
				<input id="password" type="password" name="password" required>
				@error('password')
					<small style="color:red">{{ $message }}</small>
				@enderror

				<label>
					<input type="checkbox" name="remember" value="1"> Remember me
				</label>

				<button type="submit">Login</button>
			</form>
		</main>
	</body>
</html>
