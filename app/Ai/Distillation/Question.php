<?php

namespace App\Ai\Distillation;

final readonly class Question
{
    public const CONSENTED = 'consented';

    public const TEACHER_WRITTEN = 'teacher-written';

    public function __construct(
        public string $text,
        public string $origin,
        public string $category,
        public string $article = '',
        public string $conversation = '',
    ) {}

    public function id(): string
    {
        return substr(hash('sha256', $this->category."\n".$this->text), 0, 16);
    }
}
