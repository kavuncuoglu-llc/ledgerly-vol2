@extends('layouts.app')

@section('title', $customer->name)

@section('content')
    <p class="crumbs"><a href="{{ route('customers.index') }}">Customers</a> /</p>
    <h1>{{ $customer->name }}</h1>

    <dl class="facts">
        <div><dt>Contact</dt><dd>{{ $customer->contact_name }} · {{ $customer->email }}</dd></div>
        <div><dt>Plan</dt><dd>{{ ucfirst($customer->plan) }} · {{ $customer->seats }} {{ Str::plural('seat', $customer->seats) }}</dd></div>
        <div><dt>Account</dt><dd>{{ ucfirst(str_replace('_', '-', $customer->status)) }}</dd></div>
        <div><dt>Currency</dt><dd>{{ $customer->currency }} · {{ $customer->country }} · {{ $customer->timezone }}</dd></div>
        <div><dt>Customer since</dt><dd>{{ $customer->created_at->toDateString() }}</dd></div>
    </dl>

    <h2>Invoices</h2>
    <table class="table">
        <thead>
        <tr><th>Invoice</th><th>Status</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Late fee</th></tr>
        </thead>
        <tbody>
        @forelse ($invoices as $invoice)
            <tr>
                <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                <td><x-invoice-status :status="$invoice->status" /></td>
                <td>{{ $invoice->issued_at->toDateString() }}</td>
                <td>{{ $invoice->due_at->toDateString() }}</td>
                <td class="num">@money($invoice->total, $invoice->currency)</td>
                <td class="num">{{ $invoice->late_fee_cents ? \App\Support\Money::formatCents($invoice->late_fee_cents, $invoice->currency) : '–' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No invoices yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $invoices->links() }}

    <h2>Recent support tickets</h2>
    <table class="table">
        <thead>
        <tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Opened</th></tr>
        </thead>
        <tbody>
        @forelse ($tickets as $ticket)
            <tr>
                <td><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->number }}</a></td>
                <td>{{ $ticket->title }}</td>
                <td><x-ticket-status :status="$ticket->status" /></td>
                <td>{{ $ticket->created_at->toDateString() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No tickets.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>People</h2>
    <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
        @foreach ($users as $user)
            <tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ ucfirst($user->role) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endsection
