<?php

namespace App\Ai\Sensitive;

use App\Ai\Conversations\ConversationStore;
use App\Models\Message;
use App\Models\SensitiveRoutingRecord;
use App\Models\Ticket;

// Chapter 51, Topic 9: what happens to a message after the router has read it. A person is brought in with a ticket that points at the conversation (the message is not copied), the customer sees words people wrote,
// and the only thing logged is the category and the decision.
final class SensitiveRouting
{
    public function __construct(
        private SensitiveContentRouter $router,
        private ConversationStore $store,
        private RefusalDetector $detector,
    ) {}

    public function decide(Message $reply): RoutingDecision
    {
        return $this->router->route((string) $reply->question);
    }

    // The reply is filled from the text a person wrote; no model is called.
    public function handle(Message $reply, RoutingDecision $decision): void
    {
        $this->store->complete($reply, ['role' => 'assistant', 'content' => $this->textFor($decision)]);

        $ticket = $decision->needsPerson()
            ? $this->ticketFor($reply, $decision)
            : null;

        $this->record($reply, $decision, $decision->route, $ticket);
    }

    // The public widget has no account and no conversation, so there is nobody to open a ticket for: the text a person wrote, and the way to a person that it points to, is all it can give. The category and decision are still logged.
    public function forAnonymous(string $question): ?string
    {
        $decision = $this->router->route($question);

        if (! in_array($decision->category, [RoutingDecision::SAFETY, RoutingDecision::HARASSMENT], true)) {
            return null;                                                           // an off-topic question has the widget's own template (Chapter 35); only a person's words go to someone who may be in distress
        }

        SensitiveRoutingRecord::create([
            'category' => $decision->category,
            'subcategory' => $decision->subcategory,
            'decision' => $decision->route,
            'source' => $decision->source,
        ]);

        return $this->textFor($decision);
    }

    // After the assistant answered: an in-scope question that it declined goes to a person and is logged for review.
    public function afterAnswer(Message $reply, RoutingDecision $decision): bool
    {
        if (! config('sensitive-content.log.false_refusals', true)) {
            return false;
        }

        if (! $this->detector->isFalseRefusal($decision, (string) $reply->content)) {
            return false;
        }

        $ticket = $this->ticketFor($reply, new RoutingDecision(RoutingDecision::IN_SCOPE, 'false_refusal', RoutingDecision::PERSON, 'detector'), urgent: false);

        $this->record($reply, new RoutingDecision(RoutingDecision::IN_SCOPE, 'false_refusal', RoutingDecision::PERSON, 'detector'), 'false_refusal', $ticket);

        return true;
    }

    public function textFor(RoutingDecision $decision): string
    {
        $key = match ($decision->route) {
            RoutingDecision::DECLINE_AND_PERSON => "sensitive.decline.{$decision->subcategory}",
            default => "sensitive.holding.{$decision->subcategory}",
        };

        $text = __($key);

        return $text === $key
            ? __('sensitive.decline.other')
            : $text;
    }

    private function ticketFor(Message $reply, RoutingDecision $decision, bool $urgent = true): Ticket
    {
        $conversation = $reply->conversation;

        $priority = $urgent && $decision->route === RoutingDecision::PERSON_PRIORITY;

        return Ticket::firstOrCreate(
            ['idempotency_key' => "sensitive:{$reply->id}:{$decision->subcategory}"],
            [
                'customer_id' => $conversation->user->customer_id,
                'user_id' => $conversation->user_id,
                'subject' => $priority ? 'Priority: a customer message needs a person' : 'A conversation needs a person',
                'summary' => "Routed by the sensitive-content router: {$decision->category} ({$decision->subcategory}). The message is in conversation {$conversation->id}; it is not copied here.",
                'urgency' => $priority ? 'urgent' : 'normal',
                'source' => 'sensitive_router',
            ],
        );
    }

    private function record(Message $reply, RoutingDecision $decision, string $outcome, ?Ticket $ticket): void
    {
        SensitiveRoutingRecord::firstOrCreate(
            ['message_id' => $reply->id, 'decision' => $outcome],
            [
                'tenant_id' => $reply->tenant_id,
                'conversation_id' => $reply->conversation_id,
                'category' => $decision->category,
                'subcategory' => $decision->subcategory,
                'source' => $decision->source,
                'ticket_id' => $ticket?->id,
            ],
        );
    }
}
