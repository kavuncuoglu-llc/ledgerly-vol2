<?php

namespace App\Ai\Conversations;

use App\Ai\Tokens\TokenCounter;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ToolResultCompactor
{
    public function __construct(private TokenCounter $counter, private ToolRegistry $tools) {}

    public function compact(Conversation $c, ?int $behind = null): int
    {
        $compacted = 0;

        foreach ($this->candidates($c, $behind) as [$message, $tool]) {
            $stub = $this->stub($tool, $message->content);
            $tokens = $this->counter->count($stub);

            if ($tokens >= $message->tokens) {
                continue;
            }

            $message->update(['content' => $stub, 'tokens' => $tokens]);

            $compacted++;
        }

        return $compacted;
    }

    public function hasWork(Conversation $c, ?int $behind = null): bool
    {
        return $this->candidates($c, $behind)->isNotEmpty();
    }

    /** @return Collection<int, array{0: Message, 1: string}> read-tool results old enough to become stubs */
    private function candidates(Conversation $c, ?int $behind): Collection
    {
        $behind ??= config('ai.history.compact_after');

        $exchanges = Exchanges::of($c->messages()->get());

        $old = $exchanges->slice(0, max(0, $exchanges->count() - $behind));

        $found = collect();

        foreach ($old as $exchange) {
            $names = $this->toolNames($exchange);

            foreach ($exchange->messages->where('role', 'tool') as $message) {
                $name = $names[$message->tool_call_id] ?? null;

                if ($name !== null && $this->shouldCompact($name, $message)) {
                    $found->push([$message, $name]);
                }
            }
        }

        return $found;
    }

    private function toolNames(Exchange $exchange): array
    {
        $names = [];

        foreach ($exchange->messages->where('role', 'assistant') as $message) {
            foreach ($message->tool_calls ?? [] as $call) {
                $names[$call['id'] ?? ''] = $call['function']['name'] ?? '';
            }
        }

        return $names;
    }

    private function shouldCompact(string $name, Message $message): bool
    {
        if ($message->isCompacted()) {
            return false;
        }

        if ($this->kindOf($name) !== ToolKind::Read) {
            return false;                                  // writes are the record; unknown tools stay verbatim
        }

        $decoded = json_decode((string) $message->content, true);

        if (is_array($decoded) && ($decoded['ok'] ?? true) === false) {
            return false;                                  // a denial or an error: the model should remember it
        }

        return true;
    }

    private function kindOf(string $name): ?ToolKind
    {
        $tool = $this->tools->get($name) ?? $this->tools->native()->get($name);

        return $tool instanceof Tool
            ? $tool->kind()
            : null;
    }

    private function stub(string $tool, string $content): string
    {
        return json_encode([
            'compacted' => true,
            'tool' => $tool,
            'summary' => $this->summary($tool, $content),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function summary(string $tool, string $content): string
    {
        return match ($tool) {
            'lookup_invoice' => $this->invoiceSummary($content),
            'list_invoices' => $this->listingSummary($content),
            default => Str::limit(preg_replace('/\s+/', ' ', $content), 120),
        };
    }

    private function invoiceSummary(string $content): string
    {
        $result = json_decode($content, true);

        if (! is_array($result)) {
            return Str::limit($content, 120);
        }

        if (! ($result['found'] ?? false)) {
            return 'no matching invoice';
        }

        $invoice = $result['invoice'];

        $summary = "returned invoice {$invoice['number']}: {$invoice['status']}, total {$invoice['total']}";

        if (($invoice['days_overdue'] ?? 0) > 0) {
            $summary .= ", {$invoice['days_overdue']} days overdue";
        }

        if (($invoice['late_fee'] ?? 0) > 0) {
            $summary .= ", late fee {$invoice['late_fee']}";
        }

        return $summary;
    }

    private function listingSummary(string $content): string
    {
        $rows = collect(preg_split('/\R|\},\s*\{/', $content))
            ->filter(fn (string $line) => preg_match('/INV-\d+/', $line) === 1)
            ->map(fn (string $line) => $this->listedRow($line))
            ->values();

        if ($rows->isEmpty()) {
            return Str::limit(preg_replace('/\s+/', ' ', $content), 120);
        }

        $overdue = $rows->where('status', 'overdue');

        $summary = $rows->count().' invoices';

        if ($overdue->isNotEmpty()) {
            $summary .= '; '.$overdue->count().' overdue: '.$overdue->map(fn (array $row) => "{$row['number']} ({$row['days']}d)")->implode(', ');
        }

        return $summary;
    }

    private function listedRow(string $line): array
    {
        preg_match('/INV-\d+/', $line, $number);
        preg_match('/\b(draft|sent|overdue|paid|void)\b/', $line, $status);
        preg_match_all('/\d+/', $line, $numbers);

        return [
            'number' => $number[0],
            'status' => $status[1] ?? '',
            'days' => (int) end($numbers[0]),
        ];
    }
}
