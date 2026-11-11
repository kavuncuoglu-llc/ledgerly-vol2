<?php

namespace App\Providers;

use App\Evals\GoldenSet;
use App\Evals\PromptfooExporter;
use App\Evals\ReviewQueue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EvalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GoldenSet::class, fn () => GoldenSet::default());
        $this->app->bind(PromptfooExporter::class, fn () => PromptfooExporter::default());
        $this->app->bind(ReviewQueue::class, fn () => ReviewQueue::default());
    }

    public function boot(): void
    {
        if (! config('ai.eval.enabled')) {
            return;
        }

        Route::group([], base_path('routes/eval.php'));
    }
}
