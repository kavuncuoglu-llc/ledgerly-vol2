<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('python_tasks', function (Blueprint $table) {
            $table->uuid('task_id')->primary();                           // the idempotency key every message carries (Chapter 27, Topic 7)
            $table->string('kind', 40);                                   // embed
            $table->string('stream');                                     // py:tasks:embed
            $table->string('model');
            $table->string('status', 16)->default('queued');              // queued | done | failed
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('applied_count')->nullable();
            $table->string('request_id')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();            // how long Python says the work took
            $table->text('error')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('deadline_at');                             // py:listen marks a task still queued after this as failed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('python_tasks');
    }
};
