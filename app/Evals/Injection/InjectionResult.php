<?php

namespace App\Evals\Injection;

final readonly class InjectionResult
{
    public function __construct(
        public InjectionFixture $fixture,
        public bool $modelSuccess,
        public bool $systemSuccess,
        public string $shown = '',
        public array $guard = [],
        public array $toolsCalled = [],
        public ?string $error = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->fixture->id,
            'carrier' => $this->fixture->carrier,
            'goal' => $this->fixture->goal,
            'model_success' => $this->modelSuccess,
            'system_success' => $this->systemSuccess,
            'guard' => $this->guard,
            'shown' => mb_substr($this->shown, 0, 400),
            'tools_called' => $this->toolsCalled,
            'error' => $this->error,
        ];
    }
}
