<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserForget extends Command
{
    protected $signature = 'user:forget {user : The user id} {--confirm : Actually delete; without it the command only counts}';

    protected $description = 'Delete everything the assistant stored about a user: conversations, messages, memories and tool-call content';

    public function handle(CurrentTenant $tenant): int
    {
        $user = User::find($this->argument('user'));

        if ($user === null) {
            $this->error("There is no user {$this->argument('user')}.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $counts = $tenant->run(null, fn () => $this->count($user));

            $this->line("Would delete: {$counts['conversations']} conversations, {$counts['messages']} messages, {$counts['memories']} memories, {$counts['tool_calls']} tool_call rows with content. Would anonymize: {$counts['model_calls']} model_calls rows (kept counts).");
            $this->warn('Nothing was deleted. Run again with --confirm.');

            return self::FAILURE;
        }

        $counts = $tenant->run(null, fn () => DB::transaction(fn () => $this->forget($user)));

        Log::info('user.forgotten', ['user_id' => $user->id] + $counts);                       // counts only: never the content

        $this->line("Deleted: {$counts['conversations']} conversations, {$counts['messages']} messages, {$counts['memories']} memories, {$counts['tool_calls']} tool_call rows with content. Anonymized: {$counts['model_calls']} model_calls rows (kept counts). Logged to audit.");

        return self::SUCCESS;
    }

    private function count(User $user): array
    {
        $conversationIds = Conversation::where('user_id', $user->id)->pluck('id');

        return [
            'conversations' => $conversationIds->count(),
            'messages' => Message::whereIn('conversation_id', $conversationIds)->count(),
            'memories' => UserMemory::where('user_id', $user->id)->count(),
            'tool_calls' => ToolCall::where('user_id', $user->id)->count() + PendingToolCall::where('user_id', $user->id)->count(),
            'model_calls' => ModelCall::whereIn('conversation_id', $conversationIds)->count(),
        ];
    }

    private function forget(User $user): array
    {
        $conversationIds = Conversation::where('user_id', $user->id)->pluck('id');

        $counts = $this->count($user);

        ModelCall::whereIn('conversation_id', $conversationIds)->update(['conversation_id' => null, 'request' => null]);   // the row stays for cost reporting; the link to the person, and any recorded request, go

        Message::whereIn('conversation_id', $conversationIds)->delete();
        Conversation::where('user_id', $user->id)->delete();
        UserMemory::where('user_id', $user->id)->delete();
        ToolCall::where('user_id', $user->id)->delete();
        PendingToolCall::where('user_id', $user->id)->delete();

        return $counts;
    }
}
