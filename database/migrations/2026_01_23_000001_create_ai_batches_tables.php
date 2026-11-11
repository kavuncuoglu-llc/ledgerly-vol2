<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_batches', function (Blueprint $t) {
            $t->id();
            $t->string('name');                                         // judge-nightly, synthesize-help-center, ...
            $t->string('provider');                                     // the role whose batch API (or idle hardware) runs it
            $t->string('driver', 16);                                   // openai | local | fake
            $t->string('feature');                                      // the ledger feature the results are charged to
            $t->string('status', 16)->default('building');              // building | submitted | completed | failed | collected
            $t->string('provider_batch_id')->nullable();
            $t->string('input_path')->nullable();
            $t->foreignId('parent_id')->nullable();                     // a follow-up batch of the items that failed
            $t->unsignedInteger('request_count')->default(0);
            $t->unsignedInteger('result_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('collected_at')->nullable();
            $t->timestamps();
        });

        Schema::create('ai_batch_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('ai_batches')->cascadeOnDelete();
            $t->string('custom_id');                                    // our own id on every request; results come back in any order
            $t->string('status', 16)->default('pending');               // pending | ran, errored (your own hardware, before it is collected) | done | failed
            $t->json('request');
            $t->json('response')->nullable();
            $t->string('error')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->timestamps();
            $t->unique(['batch_id', 'custom_id']);                      // collecting twice is safe: the second time inserts nothing
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_batch_items');
        Schema::dropIfExists('ai_batches');
    }
};
