<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_tool_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('conversation_id')->nullable();
            $t->string('tool');
            $t->string('tool_call_id');
            $t->json('arguments');
            $t->text('summary');
            $t->json('messages')->nullable();     // the conversation so far; null when the call came in over MCP
            $t->json('options')->nullable();
            $t->string('feature')->default('tools');
            $t->unsignedSmallInteger('round')->default(1);
            $t->string('source', 16)->default('chat');
            $t->string('token_name')->nullable();
            $t->string('status', 16)->default('pending');   // pending | approved | declined
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_tool_calls');
    }
};
