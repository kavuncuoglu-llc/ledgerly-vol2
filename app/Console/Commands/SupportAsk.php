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

class SupportAsk extends Command
{
    protected $signature = 'support:ask {question : What to ask the support assistant} {--user=12 : The user id who asks (12 is the owner of Acme Studios)}';

    protected $description = 'Ask the support assistant one question from the terminal, through the semantic cache';

    public function handle(SupportAssistant $assistant, ConversationStore $store, CurrentTenant $tenant): int
    {
        $user = User::find($this->option('user'));

        if ($user === null) {
            $this->error("There is no user {$this->option('user')}.");

            return self::FAILURE;
        }

        try {
            $result = $tenant->run($user->tenant_id, fn () => $assistant->answer(
                $user,
                $this->argument('question'),
                $store->start($user, SupportPrompt::VERSION),
            ));
        } catch (ConnectionException) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error('The model server answered '.$e->response->status().'.');

            return self::FAILURE;
        }

        if ($result->cacheHit !== null) {
            $this->line(sprintf('[semantic cache hit · %.2f · cached question: "%s"]', $result->cacheHit->similarity, $result->cacheHit->question));
        }

        $this->line($result->answer());

        return self::SUCCESS;
    }
}
