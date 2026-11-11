<?php

namespace App\Console\Commands;

use App\Ai\Native\ExchangeRecorder;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class AiRecord extends Command
{
    protected $signature = 'ai:record
        {--provider=local : A provider name from config/ai.php}
        {--fixture= : The fixture name, for example tool-call}
        {--prompt= : A JSON file with messages, and optionally tools, response_format, options}
        {--stream : Record a streamed reply}';

    protected $description = 'Send one request to a real provider and save the exchange as a fixture (key redacted) for the conformance tests';

    public function handle(ExchangeRecorder $recorder): int
    {
        $fixture = (string) $this->option('fixture');
        $prompt = (string) $this->option('prompt');

        if ($fixture === '' || $prompt === '') {
            $this->error('Give --fixture and --prompt.');

            return self::FAILURE;
        }

        $file = str_starts_with($prompt, '/') ? $prompt : base_path($prompt);

        if (! is_file($file)) {
            $this->error("No prompt file at {$prompt}.");

            return self::FAILURE;
        }

        try {
            $path = $recorder->record((string) $this->option('provider'), $fixture, json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR), (bool) $this->option('stream'));
        } catch (ConnectionException $e) {
            $this->error('Could not reach the provider: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Recorded '.str_replace(base_path().'/', '', $path).' (request + response, key redacted)');

        return self::SUCCESS;
    }
}
