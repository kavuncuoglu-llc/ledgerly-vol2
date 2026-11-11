<?php

namespace App\Console\Commands;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Load\FeedbackSimulator;
use App\Ai\Load\SimulatedUsers;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\SupportAssistant;
use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;

class ConversationReplay extends Command
{
    protected $signature = 'conversation:replay {path=storage/samples/long-conversation.json : The script of customer messages} {--user=12 : The user id who asks (12 is the owner of Acme Studios)} {--every=1 : Print every Nth turn} {--users=1 : Simulated users asking at once through the queue (Chapter 24); more than one needs Horizon running}  {--timeout=1800 : Seconds to wait for the simulated users before giving up} {--feedback-rate=0 : Share of the simulated users\' answers that get a thumbs click (Chapter 30); a directory of scripts is shared out among the users}';

    protected $description = 'Replay a scripted conversation through the support assistant and show the prompt size and summary state per turn';

    public function handle(SupportAssistant $assistant, ConversationStore $store, CurrentTenant $tenant, SimulatedUsers $simulation): int
    {
        $path = str_starts_with($this->argument('path'), '/')
            ? $this->argument('path')
            : base_path($this->argument('path'));

        if (! File::exists($path)) {
            $this->error("No script at {$this->argument('path')}.");

            return self::FAILURE;
        }

        if (File::isDirectory($path)) {
            return $this->simulateScripts($simulation, $path);
        }

        $script = json_decode(File::get($path), true);

        if ((int) $this->option('users') > 1) {
            return $this->simulate($simulation, $script);
        }

        config(['queue.default' => 'sync']);                            // summaries run inline so each turn shows the state its own prompt was built from

        $user = User::find($this->option('user'));

        if ($user === null) {
            $this->error("There is no user {$this->option('user')}.");

            return self::FAILURE;
        }

        try {
            $tenant->run($user->tenant_id, fn () => $this->replay($assistant, $store, $user, $script));
        } catch (ConnectionException) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return self::FAILURE;
        } catch (RequestException $e) {
            $this->error('The model server answered '.$e->response->status().'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // Chapter 30, Build: a directory of short scripts (storage/samples/seeded), shared out among the simulated users, with a share of them clicking a thumb afterwards.
    private function simulateScripts(SimulatedUsers $simulation, string $directory): int
    {
        $scripts = collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => json_decode(File::get($file->getPathname()), true)['messages'] ?? [])
            ->filter()
            ->values()
            ->all();

        if ($scripts === []) {
            $this->error("No scripts with a \"messages\" list in {$this->argument('path')}.");

            return self::FAILURE;
        }

        $users = User::query()->whereNotNull('tenant_id')->where('role', 'owner')->orderBy('id')->limit(max(1, (int) $this->option('users')))->get()->all();

        if (count($users) < (int) $this->option('users')) {
            $this->warn(sprintf('Only %d users can be simulated (one owner per tenant, and %d tenants have one).', count($users), count($users)));
        }

        $started = now()->subSecond();

        $this->line(sprintf('%d users × up to %d turns, %d scripts, through the %s queue ...', count($users), max(array_map('count', $scripts)), count($scripts), config('queue.default')));

        $report = $simulation->run($users, $scripts, (int) $this->option('timeout'));

        $this->line($report->line());

        $rate = (float) $this->option('feedback-rate');

        if ($rate > 0) {
            $clicks = app(FeedbackSimulator::class)->run($users, $started, $rate);

            $this->line(sprintf('feedback: %d of %d answers rated (%.1f%%) · thumbs-down %d', $clicks['rated'], $clicks['replies'], $clicks['replies'] > 0 ? $clicks['rated'] / $clicks['replies'] * 100 : 0, $clicks['down']));
        }

        return $report->failed + $report->unfinished > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    // Chapter 24, Topic 8: N people at once, each through the real queue. Their TTFT comes from model_calls, so it is the number a customer would have seen.
    private function simulate(SimulatedUsers $simulation, array $script): int
    {
        $count = (int) $this->option('users');

        $first = User::find($this->option('user'));

        if ($first === null) {
            $this->error("There is no user {$this->option('user')}.");

            return self::FAILURE;
        }

        $others = User::query()
            ->whereNotNull('tenant_id')
            ->where('role', 'owner')
            ->where('id', '!=', $first->id)
            ->orderBy('id')
            ->limit($count - 1)
            ->get();

        $users = [$first, ...$others->all()];

        if (count($users) < $count) {
            $this->warn(sprintf('Only %d users can be simulated (one owner per tenant, and %d tenants have one).', count($users), count($users)));
        }

        if (config('queue.default') === 'sync') {
            $this->warn('QUEUE_CONNECTION is sync: the users run one after another inside this command. Use redis and run `php artisan horizon` to measure real concurrency.');
        }

        $this->line(sprintf('%d users × %d turns through the %s queue ...', count($users), count($script['messages']), config('queue.default')));

        $report = $simulation->run($users, $script['messages'], (int) $this->option('timeout'), fn (int $user, int $turn) => $turn === 1 ? $this->line("  user {$user} started") : null);

        $this->line($report->line());

        return $report->failed + $report->unfinished > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function replay(SupportAssistant $assistant, ConversationStore $store, User $user, array $script): void
    {
        $conversation = $store->start($user, SupportPrompt::VERSION);

        foreach ($script['messages'] as $index => $message) {
            $turn = $index + 1;

            $before = (int) ModelCall::max('id');

            $assistant->answer($user, $message, $conversation);

            if ($turn === 1 || $turn % (int) $this->option('every') === 0) {
                $this->line($this->turnLine($turn, $conversation->fresh(), $before));
            }
        }

        $followUp = $script['follow_up'] ?? null;

        if ($followUp === null) {
            return;
        }

        $this->newLine();
        $this->line('turn '.(count($script['messages']) + 1).' question: '.$followUp);
        $this->line($assistant->answer($user, $followUp, $conversation->fresh())->answer());
    }

    private function turnLine(int $turn, Conversation $conversation, int $beforeCallId): string
    {
        $prompt = ModelCall::query()
            ->where('conversation_id', $conversation->id)
            ->where('feature', 'support.reply')
            ->where('id', '>', $beforeCallId)
            ->orderBy('id')
            ->value('prompt_tokens');

        $summary = $conversation->summary
            ? "yes (through seq {$conversation->summary_through_seq})"
            : 'no';

        return sprintf('turn %2d  prompt %-6s summary: %s', $turn, number_format((int) $prompt), $summary);
    }
}
