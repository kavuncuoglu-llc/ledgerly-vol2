<?php

namespace App\Ai\Widget;

final readonly class WidgetAnswer
{
    public const ANSWER = 'answer';

    public const TEMPLATE = 'template';

    public const SEARCH = 'search';

    public const BLOCKED = 'blocked';

    public function __construct(public string $mode, public string $text, public array $sources = []) {}

    public function toArray(): array
    {
        return ['mode' => $this->mode, 'answer' => $this->text, 'sources' => $this->sources];
    }
}
