@extends('layouts.app')

@section('title', 'Support inbox')

@section('content')
    <h1>Support inbox</h1>

    <form class="filters" method="get" action="{{ route('tickets.index') }}">
        <select name="status">
            <option value="">All states</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
            @endforeach
        </select>
        <button type="submit">Filter</button>
    </form>

    <table class="table">
        <thead>
        <tr><th>Ticket</th><th>Subject</th><th>Customer</th><th>Category</th><th>Status</th><th>Opened</th></tr>
        </thead>
        <tbody>
        @forelse ($tickets as $ticket)
            <tr>
                <td><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->number }}</a></td>
                <td>{{ $ticket->title }}</td>
                <td><a href="{{ route('customers.show', $ticket->customer) }}">{{ $ticket->customer->name }}</a></td>
                <td>{{ $ticket->category ?? '–' }}</td>
                <td><x-ticket-status :status="$ticket->status" /></td>
                <td>{{ $ticket->created_at->format('M j, H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">The inbox is empty.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $tickets->links() }}
@endsection
