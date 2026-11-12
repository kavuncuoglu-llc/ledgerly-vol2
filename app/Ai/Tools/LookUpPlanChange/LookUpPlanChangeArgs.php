<?php

namespace App\Ai\Tools\LookUpPlanChange;

use App\Ai\Schema\Describe;

final readonly class LookUpPlanChangeArgs
{
    public function __construct(
        #[Describe('Always null; the account is the signed-in customer\'s own and cannot be chosen.')] public ?string $account,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['account'] ?? null);
    }

    public static function rules(): array
    {
        return [
            'account' => ['nullable', 'string', 'max:0'],
        ];
    }
}
