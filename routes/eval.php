<?php

use App\Http\Controllers\EvalAnswerController;
use Illuminate\Support\Facades\Route;

// Loaded by EvalServiceProvider only when config('ai.eval.enabled') is true (CI, a developer machine): never in production.
Route::post('eval/answer', EvalAnswerController::class)->name('eval.answer');
