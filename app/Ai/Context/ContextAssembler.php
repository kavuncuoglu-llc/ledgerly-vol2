<?php

namespace App\Ai\Context;

use App\Ai\Conversations\SummaryNote;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Security\Untrusted;
use App\Ai\Tokens\TokenCounter;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ContextAssembler
{
    public const ORDER = ['system', 'summary', 'history', 'context', 'question'];     // stable first, changing last: the order is the whole point

    private array $slots = [];

    private array $counted = [];

    private array $labelMap = [];

    public function __construct(private TokenCounter $counter, private ProviderProfile $provider) {}

    public function system(string $text, string $memories = ''): self
    {
        $content = $memories === ''
            ? $text
            : $text."\n\n".$memories;                                                // memories ride inside the fixed system message

        $this->counted['memories'] = $memories === '' ? 0 : $this->counter->count($memories);

        $this->counted['system'] = $this->counter->count($text);

        return $this->slot('system', [['role' => 'system', 'content' => $content]], breakpoint: true);
    }

    public function summary(?string $s): self
    {
        return $s
            ? $this->slot('summary', [['role' => 'system', 'content' => SummaryNote::render($s)]], breakpoint: true)
            : $this;
    }

    public function history(array $msgs): self
    {
        return $msgs === []
            ? $this
            : $this->slot('history', array_map(fn (array $message) => $this->wrapped($message), $msgs));
    }

    public function context(array $chunks, ?string $question = null): self
    {
        $chunks = (new SourcePruner($this->counter))->prune($chunks, $question);                    // Chapter 37: duplicates out, long chunks trimmed to what matches (when that is on)

        $selection = (new SourceBudget($this->counter))->fit($chunks);

        if ($selection->droppedAny()) {
            Log::info('Retrieved sources over the context budget were dropped', ['kept' => count($selection->kept), 'dropped' => count($selection->dropped), 'budget' => $selection->budget]);
        }

        $chunks = $selection->kept;

        $this->labelMap = ContextBlock::labels($chunks);                           // labels -> chunks, kept for the citation checker

        return $this->slot('context', [['role' => 'user', 'content' => ContextBlock::render($chunks)]]);
    }

    public function question(string $q): self
    {
        return $this->slot('question', [['role' => 'user', 'content' => "Question:\n".Untrusted::wrap($q, 'customer_message')]]);
    }

    // Chapter 40, Topic 4: a stored reply's prompt, built by the same slots in the same order as the live one: the earlier turns, the passages it was shown (rebuilt from the stored chunk ids), the customer's question inside the same untrusted tags, and the tool traffic of that turn. Only the system message differs, by version, and never carries the canary: that is a deployment secret, not text to train on.
    /** @return list<array> */
    public function rebuild(Message $turn, string $systemVersion): array
    {
        $assembler = new self($this->counter, $this->provider);

        $system = match ($systemVersion) {
            SupportPrompt::FINE_TUNED_VERSION => SupportPrompt::fineTuned(),
            SupportPrompt::VERSION => SupportPrompt::base(),
            default => throw new InvalidArgumentException("No system message for version [{$systemVersion}]."),
        };

        $prompt = $assembler
            ->system($system)
            ->history($turn->historyBefore())
            ->context($turn->retrievedSources(), (string) $turn->question)
            ->question((string) $turn->question)
            ->build();

        return [...$prompt->messages, ...$this->toolTraffic($turn)];
    }

    private function toolTraffic(Message $turn): array
    {
        return Message::withoutGlobalScopes()
            ->where('conversation_id', $turn->conversation_id)
            ->where('seq', '<', $turn->seq)
            ->where('seq', '>', (int) Message::withoutGlobalScopes()->where('conversation_id', $turn->conversation_id)->where('role', 'user')->where('content', $turn->question)->where('seq', '<', $turn->seq)->max('seq'))
            ->whereIn('role', ['assistant', 'tool'])
            ->orderBy('seq')
            ->get()
            ->map(fn (Message $message) => array_filter([
                'role' => $message->role,
                'content' => $message->content,
                'tool_calls' => $message->tool_calls,
                'tool_call_id' => $message->tool_call_id,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    public function build(): AssembledPrompt
    {
        $messages = [];
        $breakdown = [];
        $prefixMessages = null;

        foreach (self::ORDER as $name) {
            if (! isset($this->slots[$name])) {
                continue;
            }

            ['messages' => $slotMessages, 'breakpoint' => $breakpoint] = $this->slots[$name];

            foreach ($slotMessages as $message) {
                $messages[] = $message;

                $breakdown[$name] = ($breakdown[$name] ?? 0) + $this->tokensOf($message);
            }

            if ($breakpoint) {
                $prefixMessages ??= count($messages);                                // the first breakpoint ends the prefix every customer shares
            }

            if ($breakpoint && $this->provider->supportsCacheBreakpoints()) {
                $messages[array_key_last($messages)]['cache_control'] = ['type' => 'ephemeral'];
            }
        }

        $breakdown = $this->withMemories($breakdown);

        $this->warnAboutSlotsOverBudget($breakdown);

        return new AssembledPrompt($messages, $breakdown, $this->labelMap, $prefixMessages ?? 0);
    }

    // The customer's earlier turns are stored as typed; tool results and assistant turns are not text a customer wrote.
    private function wrapped(array $message): array
    {
        if (($message['role'] ?? '') !== 'user') {
            return $message;
        }

        $message['content'] = Untrusted::wrap((string) ($message['content'] ?? ''), 'customer_message');

        return $message;
    }

    private function slot(string $name, array $messages, bool $breakpoint = false): self
    {
        $this->slots[$name] = ['messages' => $messages, 'breakpoint' => $breakpoint];

        return $this;
    }

    private function tokensOf(array $message): int
    {
        $text = (string) ($message['content'] ?? '');

        if (! empty($message['tool_calls'])) {
            $text .= json_encode($message['tool_calls'], JSON_UNESCAPED_SLASHES);
        }

        return $this->counter->count($text);
    }

    private function withMemories(array $breakdown): array
    {
        if (($this->counted['memories'] ?? 0) === 0) {
            return $breakdown;
        }

        $memories = $this->counted['memories'];

        $system = $this->counted['system'];

        $breakdown['system'] = $system;                                            // the system message was counted as one: split it for the stats

        return ['system' => $system, 'memories' => $memories] + $breakdown;
    }

    private function warnAboutSlotsOverBudget(array $breakdown): void
    {
        foreach (config('ai.slots', []) as $name => $budget) {
            if (($breakdown[$name] ?? 0) > $budget) {
                Log::warning('Context slot over budget', ['slot' => $name, 'tokens' => $breakdown[$name], 'budget' => $budget]);
            }
        }
    }
}
