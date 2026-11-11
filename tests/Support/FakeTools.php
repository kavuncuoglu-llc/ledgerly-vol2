<?php

namespace Tests\Support;

use App\Ai\Schema\Describe;
use App\Ai\Tools\Mcp\McpClient;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolDefaults;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use RuntimeException;

final readonly class RefundArgs
{
    public function __construct(
        #[Describe('Invoice number')] public string $invoice_number,
        #[Describe('Amount to refund')] public float $amount,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['invoice_number'], (float) $d['amount']);
    }

    public static function rules(): array
    {
        return ['invoice_number' => ['required', 'string'], 'amount' => ['required', 'numeric', 'min:0.01']];
    }
}

final readonly class NoArgs
{
    public function __construct() {}

    public static function fromArray(array $d): self
    {
        return new self;
    }

    public static function rules(): array
    {
        return [];
    }
}

final class RefundTool implements Tool
{
    use ToolDefaults;

    public static int $runs = 0;

    public function name(): string
    {
        return 'issue_refund';
    }

    public function description(): string
    {
        return 'Refund part of an invoice.';
    }

    public function arguments(): string
    {
        return RefundArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteHighRisk;
    }

    public function describeIntent(array $args, ToolContext $ctx): string
    {
        return sprintf('Refund $%.2f late fee on invoice #%s', $args['amount'], $args['invoice_number']);
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        self::$runs++;

        return ToolResult::ok(['refunded' => $args->amount]);
    }
}

final class BrokenTool implements Tool
{
    use ToolDefaults;

    public function name(): string
    {
        return 'broken_tool';
    }

    public function description(): string
    {
        return 'Always fails.';
    }

    public function arguments(): string
    {
        return NoArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        throw new RuntimeException('SQLSTATE[08006] secret connection string');
    }
}

final class OnceTool implements Tool
{
    use ToolDefaults;

    public static int $runs = 0;

    public function name(): string
    {
        return 'once_tool';
    }

    public function description(): string
    {
        return 'May be called once per conversation.';
    }

    public function arguments(): string
    {
        return NoArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function perConversationLimit(): int
    {
        return 1;
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        self::$runs++;

        return ToolResult::ok(['n' => self::$runs]);
    }
}

final class FakeMcpClient implements McpClient
{
    public array $calls = [];

    public function __construct(private array $tools = [], private array $reply = ['isError' => false, 'text' => 'ok'], private bool $down = false) {}

    public function listTools(): array
    {
        if ($this->down) {
            throw new RuntimeException('connection refused');
        }

        return $this->tools;
    }

    public function callTool(string $name, array $arguments): array
    {
        $this->calls[] = [$name, $arguments];

        return $this->reply;
    }
}
