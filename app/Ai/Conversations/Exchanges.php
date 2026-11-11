<?php

namespace App\Ai\Conversations;

use App\Models\Message;
use Illuminate\Support\Collection;

final class Exchanges
{
    /**
     * @param  Collection<int, Message>  $messages  in seq order
     * @return Collection<int, Exchange> a user turn starts a new exchange
     */
    public static function of(Collection $messages): Collection
    {
        $groups = [];

        foreach ($messages as $message) {
            if ($message->role === 'user' || $groups === []) {
                $groups[] = collect();
            }

            $groups[array_key_last($groups)]->push($message);
        }

        return collect($groups)->map(fn (Collection $group) => new Exchange($group));
    }

    /** @return Collection<int, Exchange> only the exchanges that ended in an answer; a failed turn is not replayed */
    public static function complete(Collection $messages): Collection
    {
        return self::of($messages)
            ->filter(fn (Exchange $exchange) => $exchange->isComplete())
            ->values();
    }
}
