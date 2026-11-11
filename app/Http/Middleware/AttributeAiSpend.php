<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

// Chapter 36, Topic 3: tenant and user ride along into every job. Context is carried into queued jobs automatically, so a job that runs minutes later in another process still knows whose work it is doing.
class AttributeAiSpend
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add('tenant_id', $request->user()?->tenant_id);
        Context::add('user_id', $request->user()?->id);
        Context::add('channel', $request->user() ? 'app' : 'anonymous');

        return $next($request);
    }
}
