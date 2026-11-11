<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(CurrentTenant::class)->resolve();

        if ($tenant === false) {
            return;
        }

        if ($tenant === null) {
            $builder->whereRaw('1 = 0');   // fail closed: no tenant known, nothing visible

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenant);
    }
}
