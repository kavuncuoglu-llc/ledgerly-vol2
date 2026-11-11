<?php

namespace App\Ai\Load;

use App\Ai\Feedback\RecordFeedback;
use App\Models\AnswerFeedback;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;

// Chapter 30, Build: simulated customers who click a thumb on a share of their answers. The draw is a hash of the seed and the reply id, so the same replay rates the same replies.
final class FeedbackSimulator
{
    public function __construct(private RecordFeedback $record, private CurrentTenant $tenants) {}

    /**
     * @param  list<User>  $users
     * @return array{rated: int, down: int, replies: int}
     */
    public function run(array $users, CarbonInterface $since, float $rate, int $seed = 7): array
    {
        $replies = 0;
        $rated = 0;
        $down = 0;

        foreach ($users as $user) {
            $this->tenants->run($user->tenant_id, function () use ($user, $since, $rate, $seed, &$replies, &$rated, &$down) {
                $conversations = Conversation::query()->where('user_id', $user->id)->where('created_at', '>=', $since)->pluck('id');

                $messages = Message::query()->whereIn('conversation_id', $conversations)->whereNotNull('question')->where('status', 'completed')->orderBy('id')->get();

                foreach ($messages as $reply) {
                    $replies++;

                    if (! self::clicks($seed, $reply->id, $rate)) {
                        continue;
                    }

                    $rating = self::thumbsDown($seed, $reply->id)
                        ? 'down'
                        : 'up';

                    ($this->record)($reply, $rating, $rating === 'down' ? self::reason($seed, $reply->id) : null);

                    $rated++;
                    $down += $rating === 'down' ? 1 : 0;
                }
            });
        }

        return ['rated' => $rated, 'down' => $down, 'replies' => $replies];
    }

    public static function clicks(int $seed, int $replyId, float $rate): bool
    {
        return (crc32("click:{$seed}:{$replyId}") % 10000) / 10000 < $rate;
    }

    public static function thumbsDown(int $seed, int $replyId): bool
    {
        return crc32("down:{$seed}:{$replyId}") % 100 < 30;                          // most people who click, click up
    }

    public static function reason(int $seed, int $replyId): string
    {
        return AnswerFeedback::REASONS[crc32("reason:{$seed}:{$replyId}") % count(AnswerFeedback::REASONS)];
    }
}
