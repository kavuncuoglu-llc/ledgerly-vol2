<?php

namespace App\Notifications;

use App\Notifications\Channels\TeamChannel;
use Illuminate\Notifications\Notification;

class QuarterlyReviewReady extends Notification
{
    public function __construct(private readonly string $quarter, private readonly string $owner) {}

    public function via(mixed $notifiable): array
    {
        return [TeamChannel::class];
    }

    public function toTeam(mixed $notifiable): string
    {
        return "The {$this->quarter} AI review is ready in docs/reviews/{$this->quarter}.md. Owner: {$this->owner}. Half a day: read, test, decide, update.";
    }
}
