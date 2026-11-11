<?php

namespace App\Console\Commands;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\SupportAssistant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;

class SupportAskBatch extends Command
{
    protected $signature = 'support:ask-batch {path=storage/samples/howto.json : A JSON file with a "questions" list} {--user=12 : The user id who asks} {--list=questions : Which list to ask: questions, paraphrases or different}';

    protected $description = 'Ask a list of questions, each in its own conversation, and report how many the semantic cache answered';

    public function handle(SupportAssistant $assistant, ConversationStore $store, CurrentTenant $tenant): int
    {
        $path = str_starts_with($this->argument('path'), '/')
            ? $this->argument('path')
            : base_path($this->argument('path'));

        if (! File::exists($path)) {
            $this->error("No file at {$this->argument('path')}.");

            return self::FAILURE;
        }

        $questions = json_decode(File::get($path), true)[$this->option('list')] ?? [];

        if ($questions === []) {
            $this->error("The file has no \"{$this->option('list')}\" list.");

            return self::FAILURE;
        }

        $user = User::find($this->option('user'));

        if ($user === null) {
            $this->error("There is no user {$this->option('user')}.");

            return self::FAILURE;
        }

        try {
            [$hits, $elapsedMs] = $tenant->run($user->tenant_id, fn () => $this->askAll($assistant, $store, $user, $questions));
        } catch (ConnectionException) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error('The model server answered '.$e->response->status().'.');

            return self::FAILURE;
        }

        $this->line(sprintf('%d answered · %d cache hits · avg %s ms', count($questions), $hits, number_format($elapsedMs / count($questions))));

        return self::SUCCESS;
    }

    private function askAll(SupportAssistant $assistant, ConversationStore $store, User $user, array $questions): array
    {
        $hits = 0;
        $elapsedMs = 0;

        foreach ($questions as $question) {
            $started = hrtime(true);

            $result = $assistant->answer($user, $question, $store->start($user, SupportPrompt::VERSION));

            $elapsedMs += (hrtime(true) - $started) / 1_000_000;

            $hits += (int) ($result->cacheHit !== null);
        }

        return [$hits, $elapsedMs];
    }
}
