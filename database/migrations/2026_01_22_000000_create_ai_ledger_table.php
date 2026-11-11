<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_ledger', function (Blueprint $t) {
            $t->id();
            $t->timestampTz('occurred_at')->index();
            $t->string('entry_id', 40)->nullable()->unique();      // the Redis stream entry this row came from: a batch written twice (a crash between insert and acknowledge) inserts nothing the second time
            $t->foreignId('model_call_id')->nullable();             // null for adjustments
            $t->string('trace_id', 32)->nullable();
            $t->foreignId('tenant_id')->nullable()->index();        // null = anonymous (Chapter 35, Topic 7) or work no tenant asked for
            $t->foreignId('user_id')->nullable();
            $t->string('feature')->index();                         // support.reply, ticket.classify, ...
            $t->string('team');                                     // from config: who owns the feature
            $t->string('provider');
            $t->string('model');
            $t->unsignedInteger('input_tokens');
            $t->unsignedInteger('cached_input_tokens');
            $t->unsignedInteger('cache_write_tokens');
            $t->unsignedInteger('output_tokens');
            $t->string('price_version');                            // which price map priced this row
            $t->bigInteger('cost_micro_usd');                       // integer millionths of a dollar; negative for corrections
            $t->string('kind')->default('call');                    // call | adjustment | hardware | batch | training
            $t->string('reason')->nullable();
            $t->index(['team', 'occurred_at']);
            $t->index(['feature', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_ledger');
    }
};
