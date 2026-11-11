@extends('layouts.app')

@section('title', $ticket->number)

@section('content')
    <p class="crumbs"><a href="{{ route('tickets.index') }}">Support inbox</a> /</p>
    <h1>{{ $ticket->number }}: {{ $ticket->title }} <x-ticket-status :status="$ticket->status" /></h1>

    <dl class="facts">
        <div><dt>Customer</dt><dd><a href="{{ route('customers.show', $ticket->customer) }}">{{ $ticket->customer->name }}</a></dd></div>
        <div><dt>From</dt><dd>{{ $ticket->user?->name ?? 'Unknown' }} · via {{ $ticket->source }}</dd></div>
        <div><dt>Category</dt><dd>{{ $ticket->category ?? 'Not labelled yet' }}</dd></div>
        <div><dt>Opened</dt><dd>{{ $ticket->created_at->format('M j, Y H:i') }}</dd></div>
        @if ($ticket->invoice_number)
            <div><dt>Invoice</dt><dd>
                @if ($invoice)
                    <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                @else
                    {{ $ticket->invoice_number }}
                @endif
            </dd></div>
        @endif
    </dl>

    @if ($ticket->body)
        <div class="message">{!! nl2br(e($ticket->body)) !!}</div>
    @endif

    @if ($ticket->summary)
        <h2>Summary</h2>
        <p>{{ $ticket->summary }}</p>
    @endif

    @if ($ticket->triageRun && $ticket->triageRun->status !== 'running')
        @php($triage = $ticket->triageRun)
        <h2>Triage</h2>
        @if ($triage->status === 'human')
            <p>Needs a person: {{ $triage->reason }}.</p>
        @else
            <dl class="facts">
                <div><dt>Urgency</dt><dd>{{ $triage->urgency }}</dd></div>
                <div><dt>Assigned to</dt><dd>{{ $triage->assignee }}</dd></div>
                @if (! empty($triage->findings['summary']))
                    <div><dt>Findings</dt><dd>{{ $triage->findings['summary'] }}</dd></div>
                @endif
            </dl>
            @if ($triage->draft)
                <h3>Drafted first reply <small>({{ $triage->draft_status }}{{ $triage->draft_citations_ok === false ? ', citations need checking' : '' }}; not sent)</small></h3>
                <div class="message">{!! nl2br(e($triage->draft)) !!}</div>
            @endif
        @endif
    @endif
@endsection
