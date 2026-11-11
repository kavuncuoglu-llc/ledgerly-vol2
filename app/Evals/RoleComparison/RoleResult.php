<?php

namespace App\Evals\RoleComparison;

// Chapter 41, Figure 41-6: one column of the comparison. `metrics` holds only what was measured; a check that could not run says why in `notes`.
final class RoleResult
{
    /** @param array<string, float|int|string|null> $metrics
     * @param array<string, string> $notes */
    public function __construct(public string $role, public string $provider, public string $model, public array $metrics = [], public array $notes = [], public ?string $skipped = null) {}

    public function toArray(): array
    {
        return ['role' => $this->role, 'provider' => $this->provider, 'model' => $this->model, 'metrics' => $this->metrics, 'notes' => $this->notes, 'skipped' => $this->skipped];
    }

    public static function fromArray(array $row): self
    {
        return new self($row['role'], $row['provider'], $row['model'], $row['metrics'] ?? [], $row['notes'] ?? [], $row['skipped'] ?? null);
    }
}
