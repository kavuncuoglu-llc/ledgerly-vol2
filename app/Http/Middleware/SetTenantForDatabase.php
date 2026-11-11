<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantForDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->setting((string) ($request->user()?->tenant_id ?? 0));      // 0 matches no tenant: a guest or a staff member sees only shared rows

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->setting('');
    }

    private function setting(string $tenantId): void
    {
        DB::statement('SELECT set_config(?, ?, false)', ['app.tenant_id', $tenantId]);
    }
}
