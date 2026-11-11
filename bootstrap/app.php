<?php

use App\Ai\Conversations\NoTenant;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AttributeAiSpend;
use App\Http\Middleware\SetTenantForDatabase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        $middleware->web(append: [
            SetTenantForDatabase::class,
            AttributeAiSpend::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ConnectionException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => 'The model server did not answer. Is Ollama running at '.config('ai.base_url').'?'], 503);
        });

        $exceptions->render(function (NoTenant $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => 'The assistant is for customer accounts.'], 403);
        });
    })->create();
