@extends('layouts.app')

@section('title', $label)

@section('content')
    <p class="crumbs"><a href="{{ route('help.index') }}">Help center</a> /</p>
    <h1>{{ $label }}</h1>

    <ul class="article-list">
        @foreach ($articles as $article)
            <li><a href="{{ $article->url() }}">{{ $article->title }}</a>
                @if ($article->summary)<span class="muted"> – {{ $article->summary }}</span>@endif
            </li>
        @endforeach
    </ul>
@endsection
