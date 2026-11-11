<?php

namespace App\Http\Controllers;

use App\Help\HelpArticle;
use Illuminate\Contracts\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index', [
            'categories' => HelpArticle::categories(),
        ]);
    }

    public function category(string $category): View
    {
        $articles = HelpArticle::inCategory($category);

        abort_if($articles->isEmpty(), 404);

        return view('help.category', [
            'category' => $category,
            'label' => HelpArticle::categoryLabel($category),
            'articles' => $articles,
        ]);
    }

    public function show(string $category, string $slug): View
    {
        $article = HelpArticle::find($category, $slug);

        abort_if($article === null, 404);

        return view('help.show', [
            'article' => $article,
            'siblings' => HelpArticle::inCategory($category)->reject(fn (HelpArticle $other) => $other->slug === $slug),
        ]);
    }
}
