<?php

namespace App\Ai\Support;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\Capability;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schemas\Category;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ClassifyTicket
{
    public function __construct(private StructuredClient $structured, private AiManager $ai) {}

    public function __invoke(string $message, array $options = []): Category
    {
        $model = $this->ai->for('ticket.classify');

        if ($model instanceof SupportsGrammar && $model->supports(Capability::Grammar)) {
            $category = $this->constrained($model, $message, $options);                                 // llama-server: an invalid label is impossible, not validated away

            if ($category !== null) {
                return $category;
            }
        }

        return $this->structured->extract(
            TicketData::class,
            TicketClassifyPrompt::messages($message),
            ['prompt_version' => TicketClassifyPrompt::VERSION] + $options,
            feature: 'ticket.classify',
        )->category;
    }

    // null when the reply was cut before the label ended (maxTokens 4 is enough for the five Llama labels, not for every tokenizer): the schema route takes over
    private function constrained(SupportsGrammar $model, string $message, array $options): ?Category
    {
        $label = $model->completeWithGrammar(
            TicketClassifyPrompt::rendered($message),
            grammar: TicketClassifyPrompt::GBNF,
            maxTokens: 4,
            options: config('ai.profiles')[$options['profile'] ?? 'deterministic'] ?? [],
            feature: 'ticket.classify',
        );

        return Category::tryFrom(trim($label));
    }

    /** Chapter 9's self-consistency: the same question classified $n times at a temperature above zero. A split vote is the signal, not noise. */
    public function sample(string $message, int $n = 3): Votes
    {
        $labels = [];

        for ($i = 0; $i < $n; $i++) {
            try {
                $labels[] = ($this)($message, ['profile' => 'assistant'])->value;
            } catch (Throwable $e) {
                Log::warning('A classifier vote was lost', ['error' => $e->getMessage()]);       // fewer votes, not a failed ticket
            }
        }

        return new Votes($labels);
    }

    public function forTicket(Ticket $ticket): Category
    {
        $category = ($this)($ticket->body ?: $ticket->title);

        $ticket->update(['category' => $category->value]);

        return $category;
    }
}
