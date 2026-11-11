<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_signals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->index();
            $t->unsignedBigInteger('customer_id')->index();
            $t->date('computed_on')->index();             // the day of the reply
            $t->string('prompt_version', 32)->nullable();
            $t->string('variant', 32)->nullable();
            $t->boolean('reasked')->default(false);       // the next question, within two minutes, was about the same thing
            $t->boolean('ticket_followup')->default(false);   // the customer opened a ticket on the topic within the hour
            $t->boolean('escalated')->default(false);    // the assistant opened a ticket, or the customer was handed to a person
            $t->boolean('resolved')->default(false);     // none of the above
            $t->timestamps();
        });

        Schema::create('online_judgments', function (Blueprint $t) {
            $t->id();
            $t->string('subject', 32);                     // message:123 or shadow:7
            $t->unsignedBigInteger('message_id')->index();
            $t->string('variant', 64)->nullable();
            $t->string('metric', 48);                      // faithfulness | response_relevancy | conversation_completeness | knowledge_retention
            $t->float('score');
            $t->string('judge')->nullable();
            $t->string('task_id', 36)->nullable()->index();
            $t->timestamps();

            $t->unique(['subject', 'metric', 'task_id']);
            $t->index(['metric', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_judgments');
        Schema::dropIfExists('answer_signals');
    }
};
