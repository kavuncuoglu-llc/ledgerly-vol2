<?php

namespace App\Evals;

final readonly class GateReport
{
    /** @param list<GateResult> $results */
    public function __construct(public array $results) {}

    public function passedAll(): bool
    {
        return $this->qualityFailures() === []
            && $this->neverFailures() === [];
    }

    /** @return list<GateResult> */
    public function qualityFailures(): array
    {
        return $this->failures(GateResult::QUALITY);
    }

    /** @return list<GateResult> */
    public function neverFailures(): array
    {
        return $this->failures(GateResult::NEVER);
    }

    public function passedQuality(): bool
    {
        return $this->qualityFailures() === [];
    }

    public function passedNever(): bool
    {
        return $this->neverFailures() === [];
    }

    public function describe(): string
    {
        $failed = array_merge($this->neverFailures(), $this->qualityFailures());

        return $failed === []
            ? 'ok'
            : implode('; ', array_map(fn (GateResult $result) => ($result->kind === GateResult::NEVER ? 'NEVER: ' : '').$result->gate.' ('.$result->reason.')', $failed));
    }

    public function toArray(): array
    {
        return array_map(fn (GateResult $result) => $result->toArray(), $this->results);
    }

    /** @return list<GateResult> */
    private function failures(string $kind): array
    {
        return array_values(array_filter($this->results, fn (GateResult $result) => $result->kind === $kind && ! $result->passed));
    }
}
