<?php

namespace App\Mcp;

use App\Ai\Schema\SchemaBuilder;
use App\Ai\Tools\RawSchemaTool;
use App\Ai\Tools\Tool as NativeTool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRunner;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\PersonalAccessToken;

final class McpToolAdapter extends Tool
{
    public function __construct(private NativeTool $tool) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function title(): string
    {
        return Str::headline($this->tool->name());
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->tool instanceof RawSchemaTool
                ? $this->tool->inputSchema()
                : SchemaBuilder::for($this->tool->arguments()),
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->tool->kind() === ToolKind::Read,
                'destructiveHint' => $this->tool->kind() === ToolKind::WriteHighRisk,
            ],
        ];
    }

    // context from the token, gate and audit unchanged
    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if (! $user) {
            return Response::error('Unauthenticated.');
        }

        $ctx = new ToolContext(
            user: $user,
            conversationId: null,
            source: 'mcp',
            tokenName: $this->tokenName($user),
            toolCallId: (string) Str::uuid(),
        );

        $result = app(ToolRunner::class)->executeOne($this->tool, $request->all(), $ctx);   // validate → gate → run → ToolCalled

        if ($result->isPending()) {
            return Response::text("Awaiting approval in Ledgerly: {$result->approvalUrl}");
        }

        if (! $result->ok) {
            return Response::error($result->toJson());
        }

        return $result->text !== null
            ? Response::text($result->text)
            : Response::json($result->toArray());
    }

    private function actingUser(Request $request): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user;
        }

        $local = config('ai.mcp.local_user');

        return $local
            ? User::find($local)
            : null;
    }

    private function tokenName(User $user): ?string
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken
            ? $token->name
            : null;
    }
}
