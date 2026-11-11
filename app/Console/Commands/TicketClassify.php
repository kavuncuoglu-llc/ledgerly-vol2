<?php

namespace App\Console\Commands;

use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Support\ClassifyTicket;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class TicketClassify extends Command
{
    protected $signature = 'ticket:classify {path=storage/samples/tickets.json : A JSON file of {message, label} samples}';

    protected $description = 'Classify the sample support messages and compare the labels with the human ones';

    public function handle(ClassifyTicket $classify): int
    {
        $samples = $this->samples();

        if ($samples === []) {
            return self::FAILURE;
        }

        try {
            $misses = $this->misses($classify, $samples);
        } catch (ConnectionException $e) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error('The model server answered '.$e->response->status().'.');

            return self::FAILURE;
        } catch (StructuredOutputFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $agreed = count($samples) - count($misses);

        $this->line(sprintf('%d/%d agree with human labels (%d%%)', $agreed, count($samples), round($agreed / count($samples) * 100)));

        if ($misses !== []) {
            $this->line('misses:');
        }

        foreach ($misses as $miss) {
            $this->line(sprintf('  %-52s model: %-8s human: %s', '"'.$miss['message'].'"', $miss['model'], $miss['human']));
        }

        return self::SUCCESS;
    }

    private function samples(): array
    {
        $path = $this->argument('path');

        $path = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return [];
        }

        $samples = json_decode(file_get_contents($path), true);

        if (! is_array($samples)) {
            $this->error("{$path} is not a JSON list of samples.");

            return [];
        }

        return $samples;
    }

    private function misses(ClassifyTicket $classify, array $samples): array
    {
        $misses = [];

        foreach ($samples as $sample) {
            $label = $classify($sample['message'])->value;

            if ($label === $sample['label']) {
                continue;
            }

            $misses[] = ['message' => $sample['message'], 'model' => $label, 'human' => $sample['label']];
        }

        return $misses;
    }
}
