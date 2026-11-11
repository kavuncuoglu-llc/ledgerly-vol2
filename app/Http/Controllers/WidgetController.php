<?php

namespace App\Http\Controllers;

use App\Ai\Widget\ProofOfWork;
use App\Ai\Widget\PublicWidget;
use App\Http\Requests\WidgetAskRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// Chapter 35, Topic 7: the public widget's three endpoints. The page, a challenge to solve, and the question. Nothing here is authenticated, and nothing here can reach a tenant.
class WidgetController extends Controller
{
    public function show(): View
    {
        abort_unless(config('ai.widget.enabled'), 404);

        return view('widget');
    }

    public function challenge(Request $request, ProofOfWork $challenge): JsonResponse
    {
        abort_unless(config('ai.widget.enabled'), 404);

        return response()->json($challenge->issue($request->query('session')));
    }

    public function ask(WidgetAskRequest $request, ProofOfWork $challenge, PublicWidget $widget): JsonResponse
    {
        $session = (string) $request->string('session');

        if ($challenge->validSession($session) === null) {
            return response()->json(['message' => 'Unknown session.'], 422);
        }

        if (! $challenge->verify((string) $request->string('token'), (string) $request->string('nonce'), $session)) {
            return response()->json(['message' => 'The challenge was not solved.'], 422);
        }

        return response()->json($widget->ask((string) $request->string('question'), $session)->toArray());
    }
}
