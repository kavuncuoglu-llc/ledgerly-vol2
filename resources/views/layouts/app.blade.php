<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ledgerly') · Ledgerly</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('head')
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ route('dashboard') }}">Ledgerly</a>
        <nav class="main-nav" aria-label="Main">
            <a href="{{ route('customers.index') }}" @class(['active' => request()->routeIs('customers.*')])>Customers</a>
            <a href="{{ route('invoices.index') }}" @class(['active' => request()->routeIs('invoices.*')])>Invoices</a>
            <a href="{{ route('tickets.index') }}" @class(['active' => request()->routeIs('tickets.*')])>Support inbox</a>
            <a href="{{ route('help.index') }}" @class(['active' => request()->routeIs('help.*')])>Help center</a>
            <a href="{{ route('support.show') }}" @class(['active' => request()->routeIs('support.*')])>Ask support</a>
            @auth
                <a href="{{ route('settings.memories.index') }}" @class(['active' => request()->routeIs('settings.*')])>Memory</a>
                <form class="logout-form" method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="link-button">Sign out</button>
                </form>
            @endauth
        </nav>
    </div>
</header>
<main class="container">
    @yield('content')
</main>
@stack('scripts')
<footer class="container site-footer">
    Ledgerly is a fictional invoicing product. Every name, company and amount here is made up.
</footer>
</body>
</html>
