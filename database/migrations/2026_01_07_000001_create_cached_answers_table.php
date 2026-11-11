<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->index();   // null = shared across tenants (policy/how-to); set = tenant-specific
            $t->string('category');
            $t->text('question');
            $t->text('answer');
            $t->string('help_version');                         // invalidates on docs change
            $t->string('status')->default('auto');              // auto | approved | rejected
            $t->unsignedInteger('hit_count')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        DB::statement('ALTER TABLE cached_answers ADD COLUMN embedding vector(768)');
        DB::statement('CREATE INDEX cached_answers_embedding_idx ON cached_answers USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_answers');
    }
};
