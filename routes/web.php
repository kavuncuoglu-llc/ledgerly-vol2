<?php

use App\Http\Controllers\AnswerFeedbackController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BillingEmailConfirmationController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\OpsMetricsController;
use App\Http\Controllers\PersonRequestController;
use App\Http\Controllers\ReplyController;
use App\Http\Controllers\StaffApprovalController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WidgetController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

Route::get('help', [HelpController::class, 'index'])->name('help.index');
Route::get('help/{category}', [HelpController::class, 'category'])->name('help.category');
Route::get('help/{category}/{slug}', [HelpController::class, 'show'])->name('help.show');

Route::get('login', [LoginController::class, 'create'])->middleware('guest')->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware(['guest', 'throttle:10,1']);
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('support', [SupportController::class, 'show'])->middleware('auth')->name('support.show');
Route::view('support/chat', 'support.chat')->middleware('auth')->name('support.chat');
Route::get('support/person', [PersonRequestController::class, 'show'])->middleware('auth')->name('support.person');
Route::post('support/person', [PersonRequestController::class, 'store'])->middleware(['auth', 'throttle:10,1'])->name('support.person.store');
Route::post('support/ask', [SupportController::class, 'ask'])->middleware(['auth', 'throttle:ai'])->name('support.ask');

Route::get('replies/{reply}', [ReplyController::class, 'show'])->middleware('auth')->name('replies.show');
Route::post('replies/{reply}/feedback', [AnswerFeedbackController::class, 'store'])->middleware('auth')->name('replies.feedback');

Route::get('support/approvals/{pending}', [ApprovalController::class, 'show'])->middleware('auth:web,sanctum')->name('support.approvals.show');
Route::get('billing-email/confirm/{customer}', BillingEmailConfirmationController::class)->middleware('signed')->name('billing-email.confirm');
Route::post('support/approve/{pending}', [ApprovalController::class, 'approve'])->middleware(['auth', 'throttle:ai'])->name('support.approve');
Route::post('support/decline/{pending}', [ApprovalController::class, 'decline'])->middleware(['auth', 'throttle:ai'])->name('support.decline');

Route::middleware(['auth', 'can:viewPulse'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('approvals', [StaffApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{pending}/approve', [StaffApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{pending}/decline', [StaffApprovalController::class, 'decline'])->name('approvals.decline');
});

Route::post('support/conversations/{conversation}/close', [ConversationController::class, 'close'])->middleware('auth')->name('support.conversations.close');

Route::get('settings/memories', [MemoryController::class, 'index'])->middleware('auth')->name('settings.memories.index');
Route::delete('settings/memories/{memory}', [MemoryController::class, 'destroy'])->middleware('auth')->name('settings.memories.destroy');

Route::get('ops/metrics', OpsMetricsController::class)->name('ops.metrics');

Route::get('widget', [WidgetController::class, 'show'])->name('widget.show');
Route::get('widget/challenge', [WidgetController::class, 'challenge'])->middleware('throttle:widget')->name('widget.challenge');
Route::post('widget/ask', [WidgetController::class, 'ask'])->middleware('throttle:widget')->withoutMiddleware(ValidateCsrfToken::class)->name('widget.ask');
