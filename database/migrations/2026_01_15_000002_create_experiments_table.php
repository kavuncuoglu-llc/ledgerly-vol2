<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiments', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('mode', 8);                         // off | shadow | ab
            $t->unsignedTinyInteger('percent');             // shadow: share of requests re-answered; ab: share of customers in treatment
            $t->string('candidate')->nullable();            // a PHP file that returns the candidate prompt, or a class name
            $t->timestamps();
        });

        Schema::create('shadow_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->string('experiment');
            $t->string('variant', 64);
            $t->text('content');
            $t->unsignedBigInteger('model_call_id')->nullable();
            $t->json('gates');                              // Chapter 29's gates on the candidate's answer
            $t->timestamps();

            $t->unique(['message_id', 'experiment']);
            $t->index(['experiment', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_answers');
        Schema::dropIfExists('experiments');
    }
};
