<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

// Chapter 41, Topic 4: one hosted fine-tuning job, from the prepared file to the model ID. Everything needed to repeat it is here: the dataset version and its hash, the settings, the provider's ids.
class FineTuneJob extends Model
{
    public const ACTIVE = ['queued', 'running'];

    public const TERMINAL = ['succeeded', 'failed', 'cancelled'];

    public const STATES = ['prepared', 'uploaded', 'queued', 'running', 'succeeded', 'failed', 'cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'sources' => 'array',
            'submitted_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(FineTuneJobEvent::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function transitionTo(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("[{$state}] is not a fine-tune state: ".implode(', ', self::STATES).'.');
        }

        if ($this->isTerminal()) {
            return;                                                    // a finished job stays finished, whatever a late poll says
        }

        $update = ['status' => $state];

        if (in_array($state, self::TERMINAL, true)) {
            $update['finished_at'] = now();
        }

        $this->update($update);
    }

    /** @param list<array{id: string, level?: string, message: string, metrics?: array, at?: ?string}> $events */
    public function recordEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->events()->firstOrCreate(
                ['provider_event_id' => $event['id']],
                [
                    'level' => $event['level'] ?? 'info',
                    'message' => mb_substr((string) $event['message'], 0, 1000),
                    'metrics' => $event['metrics'] ?? null,
                    'occurred_at' => $event['at'] ?? now(),
                ],
            );

            $this->last_event_id = $event['id'];
        }

        $this->save();
    }
}
