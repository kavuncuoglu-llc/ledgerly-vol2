<?php

namespace App\Ai\Sensitive;

// Chapter 51, Topic 7: what the router decided about one message. `category` and `route` are all that is ever logged.
final readonly class RoutingDecision
{
    public const SAFETY = 'safety';

    public const HARASSMENT = 'harassment';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const IN_SCOPE = 'in_scope';

    public const PERSON_PRIORITY = 'person_priority';

    public const PERSON = 'person';

    public const DECLINE_AND_PERSON = 'decline_and_person';

    public const ASSISTANT = 'assistant';

    public function __construct(
        public string $category,
        public string $subcategory,
        public string $route,
        public string $source = 'patterns',
    ) {}

    public function answersWithoutModel(): bool
    {
        return $this->route !== self::ASSISTANT;
    }

    public function needsPerson(): bool
    {
        return in_array($this->route, [self::PERSON_PRIORITY, self::PERSON, self::DECLINE_AND_PERSON], true);
    }
}
