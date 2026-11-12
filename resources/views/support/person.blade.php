@extends('layouts.app')

@section('title', __('assistant.person_title'))

@section('content')
    <h1>{{ __('assistant.person_title') }}</h1>

    @if (session('ticket'))
        <p role="status">{{ __('assistant.person_sent', ['number' => session('ticket')]) }}</p>
    @else
        <p>{{ __('assistant.person_intro') }}</p>

        <form method="post" action="{{ route('support.person.store') }}">
            @csrf
            <label for="note">{{ __('assistant.person_label') }}</label>
            <textarea id="note" name="note" rows="5" maxlength="2000" required>{{ old('note') }}</textarea>
            @error('note')<p role="alert">{{ $message }}</p>@enderror
            <button type="submit">{{ __('assistant.person_submit') }}</button>
        </form>
    @endif
@endsection
