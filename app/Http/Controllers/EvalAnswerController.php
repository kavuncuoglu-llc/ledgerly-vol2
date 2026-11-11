<?php

namespace App\Http\Controllers;

use App\Evals\EvalAnswerer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Chapter 29, Figure 29-9. A deliberate exception to "no inference inside a request", fenced by the flag that registers the route, a bearer token, and fixture customers only.
class EvalAnswerController extends Controller
{
    public function __invoke(Request $request, EvalAnswerer $answerer): JsonResponse
    {
        $token = (string) config('ai.eval.token');

        abort_if($token === '', 401);

        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        set_time_limit((int) config('ai.eval.timeout', 300));                      // php artisan serve stops a request after 30 seconds; a CPU model needs longer

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'customer' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($answerer->answer($data['customer'], $data['question'])->toArray());
    }
}
