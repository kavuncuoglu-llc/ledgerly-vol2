<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    // Horizon lists every job's payload, which is customer text. Locally anyone may look; elsewhere only Ledgerly staff.
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null) => $user?->role === 'support');
    }
}
