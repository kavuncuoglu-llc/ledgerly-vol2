<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->renameColumn('cached_tokens', 'cached_input_tokens');           // Chapter 35: the book's name for the column Chapter 14 called cached_tokens
        });

        Schema::table('model_calls', function (Blueprint $table) {
            $table->unsignedInteger('cache_write_tokens')->default(0);              // input tokens the provider billed at the cache-write price
            $table->string('job_uuid', 64)->nullable()->index();                    // the queued job that made the call, so ai:cost-audit can count paid attempts per job
            $table->unsignedSmallInteger('attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropColumn(['cache_write_tokens', 'job_uuid', 'attempt']);
        });

        Schema::table('model_calls', function (Blueprint $table) {
            $table->renameColumn('cached_input_tokens', 'cached_tokens');
        });
    }
};
