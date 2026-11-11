<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_runs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();                                   // a webhook that retries, an email that arrives twice: the same event is a no-op
            $table->foreignId('ticket_id')->index();
            $table->string('status', 16);                                               // running | automated | human | failed
            $table->string('category', 16)->nullable();
            $table->decimal('agreement', 3, 2)->nullable();                             // share of the classifier votes that agreed
            $table->json('votes')->nullable();
            $table->string('urgency', 16)->nullable();
            $table->string('assignee', 40)->nullable();
            $table->json('fields')->nullable();
            $table->json('findings')->nullable();
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->text('draft')->nullable();
            $table->string('draft_status', 16)->nullable();                             // queued | approved | rejected: nothing reaches a customer unapproved
            $table->boolean('draft_citations_ok')->nullable();
            $table->string('reason')->nullable();                                       // why a person has it, or what failed
            $table->decimal('seconds', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('urgency', 16)->nullable();
            $table->string('assignee', 40)->nullable();
            $table->timestamp('triaged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['urgency', 'assignee', 'triaged_at']);
        });

        Schema::dropIfExists('triage_runs');
    }
};
