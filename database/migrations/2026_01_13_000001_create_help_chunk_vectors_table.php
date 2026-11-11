<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_chunk_vectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_chunk_id')->constrained('help_chunks')->cascadeOnDelete();
            $table->string('model');                                      // nomic-ai/nomic-embed-text-v1.5
            $table->uuid('task_id');                                      // the python_tasks row whose result wrote this vector
            $table->timestamps();

            $table->unique(['help_chunk_id', 'model']);
        });

        DB::statement('ALTER TABLE help_chunk_vectors ADD COLUMN embedding vector(768) NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('help_chunk_vectors');
    }
};
