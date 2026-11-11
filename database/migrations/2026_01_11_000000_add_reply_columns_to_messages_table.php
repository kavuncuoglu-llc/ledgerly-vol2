<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('status', 16)->default('completed')->after('role');   // pending | streaming | paused | completed | failed
            $t->text('question')->nullable()->after('content');              // a placeholder keeps the question it will answer
            $t->string('feedback', 8)->nullable()->after('model_call_id');     // up | down: Chapter 40 turns these into preference data
            $t->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropIndex(['conversation_id', 'status']);
            $t->dropColumn(['status', 'question', 'feedback']);
        });
    }
};
