<?php

namespace App\Ai\Cost\Audit;

final readonly class AuditReport
{
    /**
     * @param  array<string, array{spend: float, successes: int, actions: int, per_success: ?float, noun: string}>  $features
     * @param  SignatureResult[]  $signatures
     */
    public function __construct(
        public float $spend,
        public array $features,
        public int $tailUnits,
        public float $tailSpendShare,
        public float $tailResolvedShare,
        public array $signatures,
        public AuditData $data,
    ) {}

    public function flagged(): array
    {
        return array_values(array_filter($this->signatures, fn (SignatureResult $result) => $result->flagged));
    }

    public function toArray(): array
    {
        return [
            'spend_usd' => round($this->spend, 6),
            'features' => $this->features,
            'toxic_tail' => ['conversations' => $this->tailUnits, 'spend_share' => round($this->tailSpendShare, 4), 'resolved_share' => round($this->tailResolvedShare, 4)],
            'signatures' => array_map(fn (SignatureResult $result) => $result->toArray(), $this->signatures),
        ];
    }
}
