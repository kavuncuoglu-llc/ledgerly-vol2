<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('state_id', 36)->index();
            $table->string('agent', 40)->index();
            $table->string('feature', 60);
            $table->foreignId('user_id')->nullable();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('triage_run_id')->nullable()->index();
            $table->text('goal');
            $table->string('status', 16);                                               // running | done | exhausted | paused | failed
            $table->string('reason', 40)->nullable();                                   // rounds | tokens | time | writes | no_progress | approval | the error
            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->unsignedSmallInteger('writes')->default(0);
            $table->decimal('seconds', 8, 2)->default(0);
            $table->json('state')->nullable();                                          // serializable, so a paused run can resume from here
            $table->json('trace')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
