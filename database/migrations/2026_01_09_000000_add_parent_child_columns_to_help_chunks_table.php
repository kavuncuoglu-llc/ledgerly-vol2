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
            $table->foreignId('parent_id')->nullable()->index();                       // child -> parent section
            $table->string('kind', 8)->default('parent');                               // parent | child
            $table->text('context_header')->nullable();                                 // prepended before embedding and before the keyword index
        });

        DB::statement('DROP INDEX IF EXISTS help_chunks_search_idx');
        DB::statement('ALTER TABLE help_chunks DROP COLUMN search');
        DB::statement("ALTER TABLE help_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(context_header,'') || ' ' || coalesce(heading_path,'') || ' ' || coalesce(content,''))) STORED");
        DB::statement('CREATE INDEX help_chunks_search_idx ON help_chunks USING gin (search)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS help_chunks_search_idx');
        DB::statement('ALTER TABLE help_chunks DROP COLUMN search');
        DB::statement("ALTER TABLE help_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(heading_path,'') || ' ' || coalesce(content,''))) STORED");
        DB::statement('CREATE INDEX help_chunks_search_idx ON help_chunks USING gin (search)');

        Schema::table('help_chunks', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'kind', 'context_header']);
        });
    }
};
