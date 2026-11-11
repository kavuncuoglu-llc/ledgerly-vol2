<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Http\StreamTruncated;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class AiStream extends Command
{
    protected $signature = 'ai:stream {prompt : What to ask the model} {--profile=assistant : A profile from config(ai.profiles)} {--timing : Print the time to the first delta and to [DONE]}';

    protected $description = 'Stream a reply token by token through the default provider of the Ai facade';

    public function handle(AiManager $ai): int
    {
        $started = hrtime(true);
        $firstDeltaMs = null;

        $gen = $ai->chat()->stream(ChatRequest::make([['role' => 'user', 'content' => $this->argument('prompt')]], ['profile' => $this->option('profile')], 'cli.stream'));

        try {
            foreach ($gen as $event) {
                if ($event instanceof ToolCallStarted) {
                    $this->line("[calling {$event->name}…]");

                    continue;
                }

                if (! $event instanceof Delta) {
                    continue;
                }

                $firstDeltaMs ??= $this->elapsedMs($started);

                $this->output->write($event->text);
            }

            $final = $gen->getReturn();

            $this->newLine();
            $this->line("[{$final->finishReason->value} · in {$final->usage->promptTokens} out {$final->usage->completionTokens}]");
        } catch (StreamTruncated $e) {
            $this->newLine();
            $this->warn('[stream cut off before completion]');

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->newLine();
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->newLine();
            $this->error('The model server answered '.$e->response->status().'.');

            return self::FAILURE;
        }

        if ($this->option('timing')) {
            $this->line(sprintf('first delta after %d ms · [DONE] after %d ms', $firstDeltaMs ?? 0, $this->elapsedMs($started)));
        }

        return self::SUCCESS;
    }

    private function elapsedMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
