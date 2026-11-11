<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->nullable();          // from usage, or estimated from the prefix shared with the previous call
            $table->unsignedInteger('ttft_ms')->nullable();                // streaming client: first delta
            $table->json('context_breakdown')->nullable();                 // tokens per slot, from ContextAssembler
            $table->boolean('semantic_cache_hit')->nullable();             // null = not eligible, false = eligible and missed, true = answered from the cache
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropColumn(['cached_tokens', 'ttft_ms', 'context_breakdown', 'semantic_cache_hit']);
        });
    }
};
