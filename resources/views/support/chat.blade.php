@extends('layouts.app')

@section('title', 'Ask support (Livewire)')

@push('head')
    @include('support.partials.reverb-config')
@endpush

@section('content')
    <h1>Ask the support assistant</h1>

    <p class="muted">The Livewire version of the chat (Chapter 18, Topic 5): Alpine draws the stream straight from the private channel, Livewire owns the stored record. The plain version is <a href="{{ route('support.show') }}">here</a>.</p>

    <livewire:chat />
@endsection

@push('scripts')
    <script src="{{ asset('js/echo-lite.js') }}"></script>
    <script src="{{ asset('js/reply-follow.js') }}"></script>
@endpush
