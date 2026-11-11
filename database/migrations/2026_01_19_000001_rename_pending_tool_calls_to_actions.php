<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('pending_tool_calls', 'pending_tool_actions');

        Schema::table('pending_tool_actions', function (Blueprint $table) {
            $table->string('args_hash', 64)->default('')->after('arguments');          // Chapter 33, Topic 6: the token is bound to exactly these arguments
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->after('customer_id');
            $table->string('approver', 16)->default('customer')->after('summary');     // customer: in the chat. agent: in the support queue
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('used_at')->nullable()->after('expires_at');
            $table->foreignId('approved_by')->nullable()->after('used_at')->constrained('users')->nullOnDelete();

            $table->index(['approver', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pending_tool_actions', function (Blueprint $table) {
            $table->dropIndex(['approver', 'status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['used_at', 'expires_at', 'approver', 'tenant_id', 'args_hash']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::rename('pending_tool_actions', 'pending_tool_calls');
    }
};
