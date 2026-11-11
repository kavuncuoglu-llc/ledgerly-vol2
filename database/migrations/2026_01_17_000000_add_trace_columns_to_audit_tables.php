<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['model_calls', 'tool_calls'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('trace_id', 32)->nullable()->index();       // joins the audit row to its trace in Jaeger (Chapter 31, Topic 1)
                $t->string('span_id', 16)->nullable();                 // and to the exact span
            });
        }

        Schema::table('messages', function (Blueprint $t) {
            $t->string('category', 16)->nullable();                    // what the router classified the question as (Chapter 31, Topic 8: input drift reads it)
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn('category');
        });

        foreach (['tool_calls', 'model_calls'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['trace_id']);
                $t->dropColumn(['trace_id', 'span_id']);
            });
        }
    }
};
