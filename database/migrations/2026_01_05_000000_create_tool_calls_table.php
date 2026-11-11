<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('conversation_id')->nullable();
            $t->foreignId('model_call_id')->nullable();
            $t->string('tool');
            $t->json('arguments');
            $t->string('decision');      // allowed | denied | invalid | pending_approval | approved | declined
            $t->json('result')->nullable();
            $t->string('source', 16)->default('chat');   // chat | mcp
            $t->string('token_name')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamps();
            $t->index(['tool', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_calls');
    }
};
