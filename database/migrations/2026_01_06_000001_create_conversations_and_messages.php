<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('tenant_id')->index();
            $t->string('title')->nullable();
            $t->string('status')->default('open');                // open | closed | archived
            $t->string('prompt_version');                         // which SupportPrompt version it started on
            $t->text('summary')->nullable();                      // Topic 4: rolling summary of trimmed turns
            $t->unsignedInteger('summary_through_seq')->default(0);
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tenant_id')->index();                  // not in Fig 13-2: Chapter 15's row-level security policy on messages needs it
            $t->unsignedInteger('seq');                           // order within the conversation
            $t->string('role', 16);                               // system | user | assistant | tool
            $t->text('content')->nullable();
            $t->json('tool_calls')->nullable();                   // assistant turns that requested tools, verbatim
            $t->string('tool_call_id')->nullable();               // tool turns: which request this answers
            $t->unsignedInteger('tokens');                        // counted at write time
            $t->foreignId('model_call_id')->nullable();           // assistant turns: the model_calls row
            $t->timestamps();
            $t->unique(['conversation_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
