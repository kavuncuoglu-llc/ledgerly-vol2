<?php

namespace App\Mcp\Resources;

use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('The last 24 months of one customer\'s invoices as JSON. Only the token owner\'s own customer id is readable.')]
#[MimeType('application/json')]
final class CustomerInvoicesTemplate extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('ledgerly://customers/{id}/invoices');
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Unauthenticated.');
        }

        if ((int) $request->get('id') !== $user->customer_id) {
            return Response::error('No such customer.');       // scoped: another customer's id looks like a missing one
        }

        $ctx = new ToolContext(user: $user, source: 'mcp', tokenName: $user->currentAccessToken()?->name);

        $result = app(ToolRunner::class)->executeOne(app(ListInvoices::class), ['status' => null, 'months' => 24], $ctx);

        return $result->ok
            ? Response::text($result->text)
            : Response::error($result->toJson());
    }
}
