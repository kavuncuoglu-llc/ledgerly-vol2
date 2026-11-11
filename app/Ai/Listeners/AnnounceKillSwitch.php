<?php

namespace App\Ai\Listeners;

use App\Ai\Budgets\Notifier;
use App\Ai\Events\EmergencyKillSwitchActivated;

// Chapter 36, Topic 7: page the on-call engineer and post to the team channel.
final class AnnounceKillSwitch
{
    public function __construct(private Notifier $notifier) {}

    public function handle(EmergencyKillSwitchActivated $event): void
    {
        $by = $event->by?->email ?? 'the spend circuit';

        $this->notifier->oncall(
            "AI kill switch on: {$event->scope}",
            "{$by} stopped all AI spend on [{$event->scope}]. Reason: {$event->reason}\nCustomers are on the bottom rung (search results and a link to a person). Reset with: php artisan ai:kill --reset --{$this->flag($event->scope)} --reason=\"...\"",
        );
    }

    private function flag(string $scope): string
    {
        return $scope === 'global'
            ? 'app'
            : str_replace(':', '=', $scope);
    }
}
