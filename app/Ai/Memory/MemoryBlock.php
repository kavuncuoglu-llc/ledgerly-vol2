<?php

namespace App\Ai\Memory;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Collection;

final class MemoryBlock
{
    public const HEADER = 'What you know about this customer';

    public static function render(User $user): string
    {
        $facts = self::facts($user);

        if ($facts->isEmpty()) {
            return '';
        }

        $lines = $facts->map(fn (UserMemory $fact) => '- '.$fact->key.': '.preg_replace('/\s+/', ' ', trim($fact->value)));

        return self::HEADER." (they can edit this in Settings):\n".$lines->implode("\n");
    }

    public static function touch(User $user): void
    {
        $ids = self::facts($user)->modelKeys();

        if ($ids === []) {
            return;
        }

        UserMemory::query()->whereKey($ids)->toBase()->update(['last_used_at' => now()]);   // not an edit: updated_at stays
    }

    /** @return Collection<int, UserMemory> a dozen at most, in key order so the rendered bytes are stable */
    private static function facts(User $user): Collection
    {
        return UserMemory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderBy('key')
            ->limit(config('ai.memory.max_facts'))
            ->get()
            ->sortBy('key')
            ->values();
    }
}
