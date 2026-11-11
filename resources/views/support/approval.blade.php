@extends('layouts.app')

@section('title', 'Approve an action')

@section('content')
    <h1>The assistant wants to act</h1>

    <p class="muted">Nothing runs until you say yes (Chapter 11, Topic 5).</p>

    <div class="answer">
        <p><strong>{{ $pending->summary }}</strong></p>
        <p class="muted">Tool: {{ $pending->tool }} · requested {{ $pending->created_at->diffForHumans() }} · status: {{ $pending->status }}</p>
    </div>

    @if (session('answer'))
        <div class="answer">
            <p>{{ session('answer') }}</p>
        </div>
    @endif

    @if ($pending->isPending())
        <form method="POST" action="{{ route('support.approve', $pending) }}" class="ask-form">
            @csrf
            <button type="submit">Approve</button>
        </form>

        <form method="POST" action="{{ route('support.decline', $pending) }}" class="ask-form">
            @csrf
            <button type="submit" class="link-button">Decline</button>
        </form>
    @endif

    <p><a href="{{ route('support.show') }}">Back to the assistant</a></p>
@endsection
