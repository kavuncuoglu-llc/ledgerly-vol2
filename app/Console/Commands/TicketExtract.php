<?php

namespace App\Console\Commands;

use App\Ai\Prompts\TicketExtractPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Structured\StructuredOutputFailed;
use App\Ai\Structured\StructuredRefusal;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class TicketExtract extends Command
{
    private const FIELDS = ['invoice_number', 'customer_email', 'amount_mentioned', 'date_mentioned'];

    protected $signature = 'ticket:extract {path=storage/samples/tickets.json : A JSON file of {message} samples}';

    protected $description = 'Extract invoice number, email, amount and date from the sample support messages';

    public function handle(StructuredClient $structured): int
    {
        $path = str_starts_with($this->argument('path'), '/')
            ? $this->argument('path')
            : base_path($this->argument('path'));

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $samples = json_decode(file_get_contents($path), true);

        if (! is_array($samples)) {
            $this->error("{$path} is not a JSON list of samples.");

            return self::FAILURE;
        }

        $valid = 0;
        $repaired = 0;
        $failed = 0;
        $filled = array_fill_keys(self::FIELDS, 0);
        $matched = 0;

        foreach ($samples as $sample) {
            try {
                $ticket = $structured->extract(
                    TicketData::class,
                    TicketExtractPrompt::messages($sample['message']),
                    ['prompt_version' => TicketExtractPrompt::VERSION],
                    feature: 'ticket.extract',
                );
            } catch (ConnectionException $e) {
                $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

                return self::FAILURE;
            } catch (RequestException $e) {
                $this->error('The model server answered '.$e->response->status().'.');

                return self::FAILURE;
            } catch (StructuredOutputFailed|StructuredRefusal $e) {
                $failed++;

                continue;
            }

            $valid++;

            if ($ticket->meta?->repaired) {
                $repaired++;
            }

            foreach (self::FIELDS as $field) {
                if ($ticket->{$field} !== null) {
                    $filled[$field]++;
                }
            }

            if (isset($sample['expect']) && $this->matchesExpectation($ticket, $sample['expect'])) {
                $matched++;
            }
        }

        $this->line("{$valid}/".count($samples)." valid · {$repaired} repaired · {$failed} failed");
        $this->line(collect($filled)->map(fn (int $count, string $field) => "{$field} filled: {$count}")->implode('   '));
        $this->line("matches the expected fields exactly: {$matched}/".count($samples));

        return $failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function matchesExpectation(TicketData $ticket, array $expect): bool
    {
        foreach (self::FIELDS as $field) {
            if ($this->normalised($ticket->{$field}) !== $this->normalised($expect[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function normalised(mixed $value): mixed
    {
        if (is_int($value)) {
            return round((float) $value, 2);
        }

        if (is_float($value)) {
            return round($value, 2);
        }

        return $value;
    }
}
