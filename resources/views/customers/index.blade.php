@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    <h1>Customers</h1>

    <form class="filters" method="get" action="{{ route('customers.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search name, contact or email">
        <button type="submit">Search</button>
    </form>

    <table class="table">
        <thead>
        <tr><th>Customer</th><th>Contact</th><th>Plan</th><th>Status</th><th class="num">Invoices</th><th class="num">Overdue</th></tr>
        </thead>
        <tbody>
        @forelse ($customers as $customer)
            <tr>
                <td><a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a></td>
                <td>{{ $customer->contact_name }}<br><span class="muted">{{ $customer->email }}</span></td>
                <td>{{ ucfirst($customer->plan) }}</td>
                <td>{{ ucfirst(str_replace('_', '-', $customer->status)) }}</td>
                <td class="num">{{ $customer->invoices_count }}</td>
                <td class="num">{{ $customer->overdue_invoices_count }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No customers match.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $customers->links() }}
@endsection
