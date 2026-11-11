@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>

    <p class="muted">Every seeded user has the password <code>password</code>. For example <code>maya.okafor@acme-studios.example.com</code>, the owner of Acme Studios.</p>

    <form class="login-form" method="post" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        @error('email')<p class="form-error">{{ $message }}</p>@enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Sign in</button>
    </form>
@endsection
