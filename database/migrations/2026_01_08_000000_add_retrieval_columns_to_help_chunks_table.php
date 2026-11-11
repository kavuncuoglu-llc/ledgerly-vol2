<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_chunks', function (Blueprint $table) {
            $table->string('title')->default('');
            $table->string('heading_path')->default('');                                // 'Billing › Late fees › How the fee is calculated'
            $table->jsonb('tags')->nullable();                                          // jsonb, so the ? operator and GIN work: {"area":"billing","plans":["starter","pro"]}
            $table->string('help_version', 40)->default('')->index();                   // docs version; '' = never ingested by help:ingest
            $table->timestamp('source_modified_at')->nullable();
            $table->string('chunk_key', 64)->nullable()->unique();                      // sha1(source . heading_path . position): idempotent upserts
            $table->unsignedBigInteger('tenant_id')->nullable()->index();               // null = the shared public help center; set = private to one tenant
        });

        DB::statement("ALTER TABLE help_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(heading_path,'') || ' ' || coalesce(content,''))) STORED");
        DB::statement('CREATE INDEX help_chunks_search_idx ON help_chunks USING gin (search)');

        DB::statement('DROP INDEX IF EXISTS help_chunks_embedding_idx');
        DB::statement('CREATE INDEX help_chunks_embedding_hnsw ON help_chunks USING hnsw ((embedding::halfvec(768)) halfvec_cosine_ops) WITH (m = 16, ef_construction = 96)');

        DB::statement('ALTER TABLE help_chunks ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE help_chunks FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON help_chunks
            USING (
                tenant_id IS NULL
                OR nullif(current_setting('app.tenant_id', true), '') IS NULL
                OR tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON help_chunks');
        DB::statement('ALTER TABLE help_chunks NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE help_chunks DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP INDEX IF EXISTS help_chunks_embedding_hnsw');
        DB::statement('CREATE INDEX help_chunks_embedding_idx ON help_chunks USING hnsw (embedding vector_cosine_ops)');

        DB::statement('DROP INDEX IF EXISTS help_chunks_search_idx');
        DB::statement('ALTER TABLE help_chunks DROP COLUMN IF EXISTS search');

        Schema::table('help_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'heading_path',
                'tags',
                'help_version',
                'source_modified_at',
                'chunk_key',
                'tenant_id',
            ]);
        });
    }
};
