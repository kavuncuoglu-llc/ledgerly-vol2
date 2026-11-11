<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('tenant_id')->index();
            $t->string('key');                       // 'role', 'preferred_name', 'timezone', 'billing_contact', ...
            $t->text('value');
            $t->string('source');                    // 'stated' | 'extracted'
            $t->foreignId('conversation_id')->nullable();
            $t->foreignId('message_id')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'key']);         // upsert by key
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
