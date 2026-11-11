<?php

namespace App\Ai\Distillation;

// Chapter 46, Topic 2: the teacher as the dataset's manifest names it: the role, the exact model and version, where it runs, and the license basis that was recorded for training on its outputs.
final readonly class Teacher
{
    /** @param array{license: string, reviewed: string, by: string} $basis */
    public function __construct(public string $role, public string $model, public string $version, public ?string $region, public array $basis) {}

    public function toArray(): array
    {
        return ['role' => $this->role, 'model' => $this->model, 'version' => $this->version, 'region' => $this->region, 'distillation_basis' => $this->basis];
    }
}
