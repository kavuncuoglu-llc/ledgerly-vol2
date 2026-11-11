@extends('layouts.app')

@section('title', $invoice->number)

@section('content')
    <p class="crumbs"><a href="{{ route('invoices.index') }}">Invoices</a> /</p>
    <h1>{{ $invoice->number }} <x-invoice-status :status="$invoice->status" />
        @if ($invoice->isPartiallyPaid()) <span class="badge badge-partial">Partial</span> @endif
    </h1>

    <dl class="facts">
        <div><dt>Customer</dt><dd><a href="{{ route('customers.show', $invoice->customer) }}">{{ $invoice->customer->name }}</a></dd></div>
        <div><dt>Issued</dt><dd>{{ $invoice->issued_at->toDateString() }}</dd></div>
        <div><dt>Due</dt><dd>{{ $invoice->due_at->toDateString() }}</dd></div>
        @if ($invoice->daysOverdue() > 0)
            <div><dt>Days overdue</dt><dd>{{ $invoice->daysOverdue() }}</dd></div>
        @endif
        @if ($invoice->paid_at)
            <div><dt>Paid</dt><dd>{{ $invoice->paid_at->toDateString() }}</dd></div>
        @endif
        <div><dt>Currency</dt><dd>{{ $invoice->currency }}</dd></div>
    </dl>

    <h2>Line items</h2>
    <table class="table">
        <thead><tr><th>Description</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($invoice->lineItems as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                <td class="num">@money($line->unit_price, $invoice->currency)</td>
                <td class="num">@money($line->amount, $invoice->currency)</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr><td colspan="3" class="num">Subtotal</td><td class="num">@money($invoice->subtotal, $invoice->currency)</td></tr>
        <tr><td colspan="3" class="num">Tax</td><td class="num">@money($invoice->tax, $invoice->currency)</td></tr>
        <tr class="strong"><td colspan="3" class="num">Total</td><td class="num">@money($invoice->total, $invoice->currency)</td></tr>
        @if ($invoice->late_fee_cents > 0)
            <tr><td colspan="3" class="num">Late fee applied</td><td class="num">{{ \App\Support\Money::formatCents($invoice->late_fee_cents, $invoice->currency) }}</td></tr>
        @endif
        <tr><td colspan="3" class="num">Paid so far</td><td class="num">@money($invoice->amountPaid(), $invoice->currency)</td></tr>
        <tr class="strong"><td colspan="3" class="num">Balance due</td><td class="num">@money($invoice->balanceDue(), $invoice->currency)</td></tr>
        </tfoot>
    </table>

    @if ($invoice->notes)
        <h2>Notes</h2>
        <p>{{ $invoice->notes }}</p>
    @endif

    <h2>Payments</h2>
    <table class="table">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @forelse ($invoice->payments as $payment)
            <tr>
                <td>{{ $payment->paid_at->toDateString() }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</td>
                <td>{{ $payment->reference }}</td>
                <td class="num">@money($payment->amount, $invoice->currency)</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No payments recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
