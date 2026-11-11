<?php

use App\Http\Controllers\EvalAnswerController;
use App\Http\Controllers\FakeFineTunedChatController;
use Illuminate\Support\Facades\Route;

// Loaded by EvalServiceProvider only when config('ai.eval.enabled') is true (CI, a developer machine): never in production.
Route::post('eval/answer', EvalAnswerController::class)->name('eval.answer');

// Chapter 41, Topic 8: the offline stand-in for a hosted fine-tune's chat endpoint. Off unless AI_FAKE_FT_CHAT is true as well.
if (config('ai.fine_tuning.fake_chat')) {
    Route::post('fake-ft/v1/chat/completions', FakeFineTunedChatController::class)->name('fake-ft.chat');
}
