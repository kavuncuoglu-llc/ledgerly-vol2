<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('help_chunk_id')->nullable();                    // no foreign key: re-ingestion may replace the chunk, the row keeps its label and url
            $table->string('chunk_key', 64)->nullable();
            $table->string('label');
            $table->string('source');
            $table->string('heading_path');
            $table->string('url');
            $table->string('help_version', 40)->default('');
            $table->timestamps();

            $table->index(['message_id']);
        });

        DB::statement('ALTER TABLE message_sources ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE message_sources FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON message_sources
            USING (
                nullif(current_setting('app.tenant_id', true), '') IS NULL
                OR tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sources');
    }
};
