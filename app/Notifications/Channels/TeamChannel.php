<?php

namespace App\Notifications\Channels;

use App\Ai\Budgets\Notifier;
use Illuminate\Notifications\Notification;

// Chapter 50, Topic 9: the book posts to Slack through Notification::route('slack', ...). The app has no Slack package; its one way to the team channel is Notifier::channel, a Slack-compatible incoming webhook.
final class TeamChannel
{
    public function __construct(private Notifier $notifier) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        $channel = (string) $notifiable->routeNotificationFor('team');

        $this->notifier->channel("[{$channel}] ".$notification->toTeam($notifiable));
    }
}
