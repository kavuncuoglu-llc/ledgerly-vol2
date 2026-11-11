@extends('layouts.app')

@section('title', 'What the assistant remembers')

@section('content')
    <h1>What the assistant remembers about you</h1>

    <p class="muted">Facts you told the assistant, kept so you do not have to repeat them (Chapter 13, Topic 6). You can delete any of them. Facts nobody has used for a year are removed automatically.</p>

    @forelse ($memories as $memory)
        <div class="answer">
            <p><strong>{{ $memory->key }}</strong>: {{ $memory->value }}</p>
            <p class="muted">{{ $memory->source }} · saved {{ $memory->created_at->diffForHumans() }}@if ($memory->last_used_at) · last used {{ $memory->last_used_at->diffForHumans() }}@endif</p>

            <form method="POST" action="{{ route('settings.memories.destroy', $memory) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="link-button">Forget this</button>
            </form>
        </div>
    @empty
        <p>Nothing yet.</p>
    @endforelse
@endsection
