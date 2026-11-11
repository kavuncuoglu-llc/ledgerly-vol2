<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_registry', function (Blueprint $t) {
            $t->id();
            $t->string('name');                                           // billing: one fine-tune, many versions
            $t->unsignedInteger('version');
            $t->string('model');                                          // the name the server knows it by: an Ollama tag or a vLLM adapter name
            $t->string('base');                                           // meta-llama/Llama-3.1-8B-Instruct
            $t->string('base_revision')->nullable();
            $t->string('dataset');                                        // billing-v1
            $t->string('dataset_hash', 80)->nullable();                   // sha256 of its train.jsonl
            $t->string('training_run')->nullable();                       // the MLflow run
            $t->string('prompt_version');                                 // the fine-tuned SupportPrompt version it was trained with
            $t->json('artifacts')->nullable();                            // adapter, merged and quantized files, each with a hash; the quantization
            $t->json('evaluations')->nullable();                          // the comparison results behind each stage change
            $t->string('stage', 16)->default('candidate');                // candidate | shadow | canary | production | previous | retired | rejected
            $t->json('history')->nullable();                              // every change: stage, when, who, why
            $t->timestamps();
            $t->unique(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_registry');
    }
};
