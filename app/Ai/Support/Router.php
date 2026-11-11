<?php

namespace App\Ai\Support;

use App\Ai\Health\ProviderHealth;
use App\Ai\Schemas\Category;
use App\Facades\Ai;
use App\Models\User;

// A fixed workflow step, never an agentic one: the classifier's category and how well its votes agree pick the tools, the retrieval scope and the path.
final class Router
{
    public function __construct(private ClassifyTicket $classifier) {}

    public function enabled(): bool
    {
        return (bool) config('ai.router.enabled');
    }

    public function route(string $question, User $user): Route
    {
        $votes = $this->classifier->sample($question, (int) config('ai.router.votes', 3));

        return $this->decide($votes)->withVotes($votes->labels());
    }

    // Chapter 25, Figure 25-7: where a feature's request goes right now. 'local' while the server answers; while its circuit is open, the named fallback if there is one, otherwise 'cache-only'.
    public function available(string $feature = 'support.reply'): string
    {
        return app(ProviderHealth::class)->routeFor($feature);
    }

    // Chapter 20, Figure 20-5: most requests are easy, a few are hard, and the bill is set by where the easy ones go.
    public function tierFor(Signals $signals): string
    {
        return match (true) {
            $signals->humanFlagged || $signals->unresolvedRounds >= 2 => 'frontier',
            $signals->agreement < (float) config('ai.router.min_agreement', 0.67) || $signals->multiAsk || $signals->isDispute => 'mid',
            default => 'small',
        };
    }

    // The provider name behind a tier. Escalation only goes upward and only to a provider that is set up: a tier nobody configured quietly stays on the best one below it, so nothing leaves the machine by accident.
    public function providerFor(Signals $signals): string
    {
        $order = ['small', 'mid', 'frontier'];

        $wanted = array_search($this->tierFor($signals), $order, true);

        for ($tier = $wanted; $tier > 0; $tier--) {
            $provider = (string) config("ai.tiers.{$order[$tier]}");

            if (Ai::configured($provider)) {
                return $provider;
            }
        }

        return (string) config('ai.tiers.small');
    }

    private function decide(Votes $votes): Route
    {
        $category = $votes->majority();

        if ($category === null) {
            return Route::fallback('the classifier gave no answer');
        }

        $agreement = $votes->agreement();

        if ($agreement < (float) config('ai.router.min_agreement', 0.67)) {
            return Route::human('ambiguous category: '.implode('/', $votes->distinct()), $votes->distinct(), $agreement, $category);
        }

        return match ($category) {
            Category::HowTo => Route::make($category, $agreement, tools: ['open_ticket'], area: null, cache: true),
            Category::Billing => Route::make($category, $agreement, tools: ['lookup_invoice', 'list_invoices', 'check_payment_status', 'calculate_proration', 'resend_invoices', 'retry_payment', 'issue_credit', 'open_ticket'], area: 'billing'),
            Category::Bug => Route::make($category, $agreement, tools: ['open_ticket'], area: 'troubleshooting', escalateAfterRounds: 2),
            Category::Account => Route::make($category, $agreement, tools: ['account_status', 'change_billing_email', 'open_ticket'], area: 'account'),
            Category::Other => Route::decline($agreement),
        };
    }
}
