<?php

namespace App\Ai\Security;

use App\Ai\Contracts\ChatRequest;
use App\Evals\NeverGates;
use App\Models\User;

// Chapter 32, Topics 4 and 7: the checks an answer passes before anyone sees it. None of them asks the model anything.
final class AnswerGuard
{
    public function __construct(
        private ExtractionGuard $extraction,
        private ForeignIdentifierScan $identifiers,
        private AnswerRenderer $renderer,
    ) {}

    public function inspect(string $answer, ChatRequest $request, User $user, string $sharedText = ''): GuardVerdict
    {
        $reason = $this->extraction->check($answer, $request);

        if ($reason !== null) {
            return new GuardVerdict(text: $answer, blockedBy: $reason);
        }

        if (NeverGates::claimsToBeHuman($answer)) {
            return new GuardVerdict(text: $answer, blockedBy: NeverGates::CLAIMS_TO_BE_HUMAN);      // Chapter 51, Topic 4: whatever the model was talked into, it does not tell a customer it is a person
        }

        $foreign = $user->customer_id === null
            ? []
            : array_values(array_diff(
                $this->identifiers->foreignInvoiceNumbers($answer, (int) $user->customer_id),
                $this->identifiers->foreignInvoiceNumbers($sharedText, (int) $user->customer_id),      // an example number in a shared help article is the article's, not a leak
            ));

        if ($foreign !== []) {
            return new GuardVerdict(text: $answer, blockedBy: 'other_tenant_identifier', foreignIdentifiers: $foreign);
        }

        return new GuardVerdict(
            text: $this->renderer->render($answer),
            strippedHosts: $this->renderer->strippedHosts($answer),
        );
    }
}
