<?php

namespace App\Ai\Training\TemplateCheck;

use Illuminate\Support\Facades\Process;
use RuntimeException;

// Chapter 40, Topic 5, Figure 40-5: the training side. training/scripts/template_check.py renders a row with the tokenizer's own chat template, the one the trainer applies, and prints the text and the token ids as JSON.
final class TrainingTemplateRenderer implements TemplateRenderer
{
    public function __construct(
        private string $tokenizer,
        private string $rowsPath,
        private int $rowIndex = 0,
        private ?string $revision = null,
    ) {}

    // An empty HF_HOME in .env means "the default cache"; handed to Python as an empty string it means a folder called hub in the working directory.
    private function environment(): array
    {
        return env('HF_HOME')
            ? []
            : ['HF_HOME' => false];
    }

    public function name(): string
    {
        return 'training ('.$this->tokenizer.')';
    }

    public function render(array $messages): RenderedPrompt
    {
        $command = ['uv', 'run', 'python', 'scripts/template_check.py', $this->tokenizer, '--rows', $this->rowsPath, '--index', (string) $this->rowIndex];

        if ($this->revision !== null) {
            array_push($command, '--revision', $this->revision);
        }

        $result = Process::path(base_path('training'))->timeout(600)->env($this->environment())->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('The training side failed: '.trim($result->errorOutput()));
        }

        $json = json_decode(trim(collect(explode("\n", trim($result->output())))->last()), true);

        if (! is_array($json) || ! isset($json['ids'])) {
            throw new RuntimeException('The training side printed no token ids.');
        }

        return RenderedPrompt::of($json['ids'], (string) ($json['text'] ?? ''));
    }
}
