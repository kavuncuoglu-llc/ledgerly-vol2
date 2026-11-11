<?php

namespace App\Ai\Tools\ChangeBillingEmail;

use App\Ai\Schema\Describe;

final readonly class ChangeBillingEmailArgs
{
    public function __construct(
        #[Describe('The new billing email address the customer asked for.')] public string $new_email,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(strtolower(trim($d['new_email'])));
    }

    public static function rules(): array
    {
        return [
            'new_email' => ['required', 'string', 'max:200', 'email:rfc'],
        ];
    }
}
