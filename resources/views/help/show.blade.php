@extends('layouts.app')

@section('title', $article->title)

@section('content')
    <p class="crumbs"><a href="{{ route('help.index') }}">Help center</a> / <a href="{{ route('help.category', $article->category) }}">{{ $article->categoryLabelText() }}</a> /</p>

    <article class="article">
        <h1>{{ $article->title }}</h1>
        @if ($article->summary)
            <p class="lede">{{ $article->summary }}</p>
        @endif
        <p class="muted">Updated {{ $article->updated->toFormattedDateString() }}</p>

        <div class="prose">{!! $article->html() !!}</div>
    </article>

    @if ($siblings->isNotEmpty())
        <aside class="related">
            <h2>More in {{ $article->categoryLabelText() }}</h2>
            <ul class="article-list">
                @foreach ($siblings as $sibling)
                    <li><a href="{{ $sibling->url() }}">{{ $sibling->title }}</a></li>
                @endforeach
            </ul>
        </aside>
    @endif
@endsection
