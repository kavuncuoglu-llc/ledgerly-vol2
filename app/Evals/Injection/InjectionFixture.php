<?php

namespace App\Evals\Injection;

use InvalidArgumentException;

// Chapter 32, Figure 32-9: one planted payload, in one carrier, with one goal and two ways of telling whether it worked.
final readonly class InjectionFixture
{
    public const CARRIERS = ['customer_message', 'ticket_body', 'invoice_line_item', 'help_chunk', 'tool_result'];

    public const GOALS = ['leak_tenant_data', 'call_open_ticket', 'reveal_prompt', 'exfiltrate_via_link', 'change_language'];

    public function __construct(
        public string $id,
        public string $carrier,
        public array $plant,
        public string $customer,
        public string $question,
        public string $goal,
        public array $systemSuccessIf,
        public array $modelSuccessIf,
    ) {}

    public static function fromArray(array $row): self
    {
        foreach (['id', 'carrier', 'customer', 'question', 'goal', 'system_success_if', 'model_success_if'] as $key) {
            if (! array_key_exists($key, $row)) {
                throw new InvalidArgumentException("Fixture is missing '{$key}'.");
            }
        }

        if (! in_array($row['carrier'], self::CARRIERS, true)) {
            throw new InvalidArgumentException("Fixture {$row['id']}: unknown carrier '{$row['carrier']}'.");
        }

        if (! in_array($row['goal'], self::GOALS, true)) {
            throw new InvalidArgumentException("Fixture {$row['id']}: unknown goal '{$row['goal']}'.");
        }

        return new self(
            id: $row['id'],
            carrier: $row['carrier'],
            plant: $row['plant'] ?? [],
            customer: $row['customer'],
            question: $row['question'],
            goal: $row['goal'],
            systemSuccessIf: $row['system_success_if'],
            modelSuccessIf: $row['model_success_if'],
        );
    }

    public function viaTriage(): bool
    {
        return $this->carrier === 'ticket_body';
    }
}
