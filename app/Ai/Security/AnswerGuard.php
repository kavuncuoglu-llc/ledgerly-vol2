<?php

namespace App\Ai\Security;

use App\Ai\Contracts\ChatRequest;
use App\Models\User;

// Chapter 32, Topics 4 and 7: the checks an answer passes before anyone sees it. None of them asks the model anything.
final class AnswerGuard
{
    public function __construct(
        private ExtractionGuard $extraction,
        private ForeignIdentifierScan $identifiers,
        private AnswerRenderer $renderer,
    ) {}

    public function inspect(string $answer, ChatRequest $request, User $user): GuardVerdict
    {
        $reason = $this->extraction->check($answer, $request);

        if ($reason !== null) {
            return new GuardVerdict(text: $answer, blockedBy: $reason);
        }

        $foreign = $user->customer_id === null
            ? []
            : $this->identifiers->foreignInvoiceNumbers($answer, (int) $user->customer_id);

        if ($foreign !== []) {
            return new GuardVerdict(text: $answer, blockedBy: 'other_tenant_identifier', foreignIdentifiers: $foreign);
        }

        return new GuardVerdict(
            text: $this->renderer->render($answer),
            strippedHosts: $this->renderer->strippedHosts($answer),
        );
    }
}
