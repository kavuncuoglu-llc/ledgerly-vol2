<?php

namespace App\Ai\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

// Chapter 36, Topic 7: the listeners page the on-call engineer and post to the team channel. The flag every BudgetGuard check reads first, and the bottom rung for the affected features, are set before this is raised.
final class EmergencyKillSwitchActivated
{
    use Dispatchable;

    public function __construct(public readonly string $scope, public readonly string $reason, public readonly ?User $by = null) {}
}
