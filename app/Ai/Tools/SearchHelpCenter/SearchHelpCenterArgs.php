<?php

namespace App\Ai\Tools\SearchHelpCenter;

use App\Ai\Schema\Describe;

final readonly class SearchHelpCenterArgs
{
    public function __construct(
        #[Describe('What to look for in the help center, in the customer\'s own words.')] public string $query,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['query']);
    }

    public static function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }
}
