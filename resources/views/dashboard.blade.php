@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Ledgerly</h1>
    <p class="muted">Working date for this app: {{ $today->toFormattedDateString() }}. Days overdue are counted from it.</p>

    <div class="stat-grid">
        <a class="stat" href="{{ route('customers.index') }}"><span class="stat-number">{{ $customerCount }}</span> customers</a>
        <a class="stat" href="{{ route('invoices.index') }}"><span class="stat-number">{{ $invoiceCount }}</span> invoices</a>
        <a class="stat" href="{{ route('invoices.index', ['status' => 'overdue']) }}"><span class="stat-number">{{ $statusCounts['overdue'] }}</span> overdue</a>
        <a class="stat" href="{{ route('invoices.index', ['late_fees' => 1]) }}"><span class="stat-number">{{ $lateFeeCount }}</span> with late fees</a>
        <a class="stat" href="{{ route('tickets.index') }}"><span class="stat-number">{{ $openTicketCount }}</span> unsolved tickets</a>
        <a class="stat" href="{{ route('help.index') }}"><span class="stat-number">{{ $articleCount }}</span> help articles</a>
    </div>

    <h2>Invoices by status</h2>
    <div class="chips">
        @foreach ($statusCounts as $value => $count)
            <a class="chip" href="{{ route('invoices.index', ['status' => $value]) }}">{{ ucfirst($value) }} <strong>{{ $count }}</strong></a>
        @endforeach
    </div>

    <h2>Longest overdue</h2>
    <table class="table">
        <thead>
        <tr><th>Invoice</th><th>Customer</th><th>Due</th><th class="num">Days overdue</th><th class="num">Total</th><th class="num">Late fee</th></tr>
        </thead>
        <tbody>
        @foreach ($overdueInvoices as $invoice)
            <tr>
                <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                <td><a href="{{ route('customers.show', $invoice->customer) }}">{{ $invoice->customer->name }}</a></td>
                <td>{{ $invoice->due_at->toDateString() }}</td>
                <td class="num">{{ $invoice->daysOverdue() }}</td>
                <td class="num">@money($invoice->total, $invoice->currency)</td>
                <td class="num">{{ $invoice->late_fee_cents ? \App\Support\Money::formatCents($invoice->late_fee_cents, $invoice->currency) : '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
