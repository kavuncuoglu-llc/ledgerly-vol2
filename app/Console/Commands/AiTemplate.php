<?php

namespace App\Console\Commands;

use App\Ai\Local\ChatTemplate;
use App\Ai\Local\OllamaNative;
use App\Ai\Local\TemplateInspector;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class AiTemplate extends Command
{
    protected $signature = 'ai:template
        {model? : Model name (defaults to AI_MODEL)}
        {--experiment= : Also send this prompt raw (template off) and through chat (template on)}
        {--save= : Write the template to this file so you have it when a first request behaves oddly}';

    protected $description = 'Print the chat template a model was trained on, and optionally run the raw-versus-chat experiment (Chapter 4)';

    public function handle(): int
    {
        $model = $this->argument('model') ?: config('ai.model');
        $inspector = new TemplateInspector(OllamaNative::fromConfig());

        try {
            $template = $inspector->inspect($model);

            if ($template->isEmpty()) {
                $this->error("Ollama reports no template for {$model}.");

                return self::FAILURE;
            }

            $this->printTemplate($template);
            $this->saveTemplate($template);

            if ($this->option('experiment')) {
                $this->printExperiment($inspector->experiment($model, $this->option('experiment')));
            }
        } catch (ConnectionException $e) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error("The model server answered {$e->response->status()}: ".$e->response->body());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printTemplate(ChatTemplate $template): void
    {
        $this->line("Model: {$template->model}".($template->family ? " ({$template->family})" : ''));
        $this->newLine();
        $this->line($template->template);
        $this->newLine();
        $this->line('Special tokens:         '.($template->specialTokens() ? implode(' ', $template->specialTokens()) : 'none found'));
        $this->line('System slot:            '.$this->yesNo($template->hasSystemSlot()));
        $this->line('Loops over messages:    '.$this->yesNo($template->hasMessagesLoop()));
        $this->line('Tools section:          '.$this->yesNo($template->hasToolsSection()));
        $this->line('Ends on assistant cue:  '.$this->yesNo($template->endsWithOpenAssistantTurn()));
    }

    private function saveTemplate(ChatTemplate $template): void
    {
        if (! $this->option('save')) {
            return;
        }

        file_put_contents($this->option('save'), $template->template);

        $this->info("Saved to {$this->option('save')}");
    }

    private function printExperiment(array $outputs): void
    {
        $this->newLine();
        $this->line('--- raw (template off): base-model-flavoured continuation ---');
        $this->line($outputs['raw']);
        $this->newLine();
        $this->line('--- chat (template on): an assistant answer ---');
        $this->line($outputs['chat']);
    }

    private function yesNo(bool $value): string
    {
        return $value
            ? 'yes'
            : 'no';
    }
}
