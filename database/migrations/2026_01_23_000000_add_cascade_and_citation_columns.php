<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->string('cascade_id', 36)->nullable()->index();            // Chapter 37: one id for every attempt a question made, cheapest tier first
            $table->unsignedSmallInteger('cascade_step')->nullable();         // 0 = the starting role
            $table->string('cascade_failed')->nullable();                     // the checks this attempt failed (citations, never_gate, wrongful_decline, truncated), comma separated
            $table->string('category', 16)->nullable();                       // the router's category, for the cascade report
        });

        Schema::table('ai_ledger', function (Blueprint $table) {
            $table->unsignedSmallInteger('cascade_step')->nullable();
        });

        Schema::table('cached_answers', function (Blueprint $table) {
            $table->json('cited_chunks')->nullable();                         // [{id, version}] of the help-center chunks the answer cited: an edit to one invalidates the answer
        });
    }

    public function down(): void
    {
        Schema::table('cached_answers', function (Blueprint $table) {
            $table->dropColumn('cited_chunks');
        });

        Schema::table('ai_ledger', function (Blueprint $table) {
            $table->dropColumn('cascade_step');
        });

        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropColumn(['cascade_id', 'cascade_step', 'cascade_failed', 'category']);
        });
    }
};
