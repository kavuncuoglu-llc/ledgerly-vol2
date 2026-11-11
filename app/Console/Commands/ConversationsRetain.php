<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\UserMemory;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class ConversationsRetain extends Command
{
    protected $signature = 'conversations:retain';

    protected $description = 'Archive closed conversations past the retention period and sweep unused memories';

    public function handle(CurrentTenant $tenant): int
    {
        [$archived, $swept] = $tenant->run(null, fn () => [$this->archiveClosedConversations(), $this->sweepUnusedMemories()]);

        $this->expireRecordedRequests();

        $this->info("Archived {$archived} closed conversations and removed {$swept} unused memories.");

        return self::SUCCESS;
    }

    private function archiveClosedConversations(): int
    {
        $cutoff = now()->subDays(config('ai.retention.closed_conversation_days', 90));

        $archived = 0;

        Conversation::where('status', 'closed')->where('updated_at', '<', $cutoff)->chunkById(500, function ($chunk) use (&$archived) {
            foreach ($chunk as $c) {
                Message::where('conversation_id', $c->id)->delete();

                $c->update(['summary' => null, 'status' => 'archived']);

                $archived++;
            }
        });

        return $archived;
    }

    private function expireRecordedRequests(): void
    {
        ModelCall::query()
            ->whereNotNull('request')
            ->where('created_at', '<', now()->subDays((int) config('ai.debug.request_days', 7)))
            ->update(['request' => null]);                                             // recorded prompts hold customer text and do not outlive their use
    }

    private function sweepUnusedMemories(): int
    {
        $cutoff = now()->subDays(config('ai.memory.expire_days'));

        return UserMemory::query()
            ->where(fn ($q) => $q->where('last_used_at', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
            ->delete();
    }
}
