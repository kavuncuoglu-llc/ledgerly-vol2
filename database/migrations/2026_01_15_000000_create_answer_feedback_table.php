<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();   // one rating per reply; a second click replaces it
            $t->foreignId('tenant_id')->index();
            $t->string('rating', 8);                                                  // up | down
            $t->string('reason', 32)->nullable();                                     // wrong_answer | did_not_answer | wanted_person, only with a thumbs-down
            $t->unsignedBigInteger('model_call_id')->nullable()->index();             // no foreign key: model_calls rows are pruned on their own schedule
            $t->string('prompt_version', 32)->nullable();
            $t->string('provider')->nullable();
            $t->string('variant', 32)->nullable();                                    // control | treatment | null when no experiment ran
            $t->timestamps();

            $t->index(['variant', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_feedback');
    }
};
