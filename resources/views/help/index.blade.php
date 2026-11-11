@extends('layouts.app')

@section('title', 'Help center')

@section('content')
    <h1>Help center</h1>

    @forelse ($categories as $category)
        <section class="help-category">
            <h2><a href="{{ route('help.category', $category['slug']) }}">{{ $category['label'] }}</a>
                <span class="muted">({{ $category['articles']->count() }})</span></h2>
            <ul class="article-list">
                @foreach ($category['articles'] as $article)
                    <li><a href="{{ $article->url() }}">{{ $article->title }}</a>
                        @if ($article->summary)<span class="muted"> – {{ $article->summary }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="muted">There are no help articles yet. Add Markdown files under <code>resources/help/&lt;category&gt;/&lt;slug&gt;.md</code>.</p>
    @endforelse
@endsection
