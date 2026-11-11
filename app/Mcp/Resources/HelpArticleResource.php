<?php

namespace App\Mcp\Resources;

use App\Help\HelpArticle;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('A Ledgerly help-center article as Markdown. Read by category and slug, for example help://articles/billing/late-fees.')]
#[MimeType('text/markdown')]
final class HelpArticleResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('help://articles/{category}/{slug}');
    }

    public function handle(Request $request): Response
    {
        $article = HelpArticle::find((string) $request->get('category'), (string) $request->get('slug'));

        if (! $article) {
            return Response::error('No such help article.');
        }

        return Response::text("# {$article->title}\n\n{$article->body}");
    }
}
