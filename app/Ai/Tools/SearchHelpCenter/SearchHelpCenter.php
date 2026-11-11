<?php

namespace App\Ai\Tools\SearchHelpCenter;

use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Retrieval\Retriever;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use Illuminate\Support\Str;

final class SearchHelpCenter implements Tool
{
    use ToolDefaults;

    private const PASSAGES = 3;

    public function __construct(private Retriever $retriever) {}

    public function name(): string
    {
        return 'search_help_center';
    }

    public function arguments(): string
    {
        return SearchHelpCenterArgs::class;
    }

    public function description(): string
    {
        return 'Search the Ledgerly help center and return the three best matching passages with their source. Use for questions about how Ledgerly works or what its policies are. Do not use for questions about this customer\'s own invoices.';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $passages = $this->retriever->retrieve($args->query, RetrievalScope::forUser($ctx->user), self::PASSAGES);   // scoped to the caller's tenant and plan, like the assistant's own retrieval

        return ToolResult::ok([
            'passages' => array_map(fn (RetrievedChunk $chunk) => [
                'source' => $chunk->source,
                'heading' => $chunk->heading,
                'label' => $chunk->label(),
                'content' => Str::limit($chunk->body(), 1200),
                'similarity' => round($chunk->similarity, 2),
            ], $passages),
        ]);
    }
}
