<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fine_tune_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('provider');                                      // the role whose fine-tuning API runs it: frontier
            $t->string('driver', 16);                                    // openai | fake
            $t->string('base_model')->nullable();
            $t->string('method', 16)->default('supervised');
            $t->json('settings')->nullable();                            // epochs, learning_rate_multiplier, batch_size, seed
            $t->string('dataset');                                       // billing-v1
            $t->string('dataset_hash', 80);                              // sha256 of train.jsonl at prepare time
            $t->json('sources');                                         // the parts of the dataset the file holds
            $t->string('file_path')->nullable();
            $t->string('validation_path')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedBigInteger('tokens_per_epoch')->default(0);
            $t->string('fingerprint', 64)->nullable()->index();          // dataset hash + base + method + settings: an accidental resubmit is an accidental bill
            $t->string('provider_file_id')->nullable();
            $t->string('provider_validation_file_id')->nullable();
            $t->string('provider_job_id')->nullable()->index();
            $t->string('status', 16)->default('prepared');              // prepared | uploaded | queued | running | succeeded | failed | cancelled
            $t->string('last_event_id')->nullable();
            $t->string('model_id')->nullable();
            $t->unsignedBigInteger('trained_tokens')->nullable();
            $t->text('failure_reason')->nullable();
            $t->string('mlflow_run_id')->nullable();
            $t->string('role')->nullable();                              // set by ai:ft:register
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fine_tune_job_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fine_tune_job_id')->constrained('fine_tune_jobs')->cascadeOnDelete();
            $t->string('provider_event_id');
            $t->string('level', 16)->default('info');
            $t->text('message');
            $t->json('metrics')->nullable();                             // step, train_loss, valid_loss
            $t->boolean('mlflow_logged')->default(false);
            $t->timestamp('occurred_at')->nullable();
            $t->unique(['fine_tune_job_id', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_tune_job_events');
        Schema::dropIfExists('fine_tune_jobs');
    }
};
