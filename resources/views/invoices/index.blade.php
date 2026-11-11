@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <h1>Invoices</h1>

    <form class="filters" method="get" action="{{ route('invoices.index') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Invoice number or customer">
        <select name="status">
            <option value="">All statuses</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
            @endforeach
        </select>
        <label class="check"><input type="checkbox" name="late_fees" value="1" @checked($withFees)> Late fee applied</label>
        <button type="submit">Filter</button>
    </form>

    <table class="table">
        <thead>
        <tr><th>Invoice</th><th>Customer</th><th>Status</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Late fee</th></tr>
        </thead>
        <tbody>
        @forelse ($invoices as $invoice)
            <tr>
                <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                <td><a href="{{ route('customers.show', $invoice->customer) }}">{{ $invoice->customer->name }}</a></td>
                <td><x-invoice-status :status="$invoice->status" /></td>
                <td>{{ $invoice->issued_at->toDateString() }}</td>
                <td>{{ $invoice->due_at->toDateString() }}</td>
                <td class="num">@money($invoice->total, $invoice->currency)</td>
                <td class="num">{{ $invoice->late_fee_cents ? \App\Support\Money::formatCents($invoice->late_fee_cents, $invoice->currency) : '–' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No invoices match.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $invoices->links() }}
@endsection
