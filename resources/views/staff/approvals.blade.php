@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
    <h1>Waiting for an agent</h1>

    <p class="muted">Credits, refunds and anything that leaves the account. Each card was written by Ledgerly's code from the checked arguments (Chapter 33, Topic 6).</p>

    @forelse ($pending as $item)
        <div class="answer">
            <p><strong>{{ $item->summary }}</strong></p>
            <p class="muted">{{ $item->customer?->name }} · requested {{ $item->created_at->diffForHumans() }} · expires {{ $item->expires_at->diffForHumans() }}</p>

            <form method="POST" action="{{ route('staff.approvals.approve', $item) }}" class="ask-form">
                @csrf
                <input type="hidden" name="token" value="{{ App\Ai\Tools\PendingAction::tokenFor($item) }}">
                <button type="submit">Approve</button>
            </form>

            <form method="POST" action="{{ route('staff.approvals.decline', $item) }}" class="ask-form">
                @csrf
                <button type="submit" class="link-button">Decline</button>
            </form>
        </div>
    @empty
        <p>Nothing is waiting.</p>
    @endforelse
@endsection
