<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_runs', function (Blueprint $table) {
            $table->string('escalated_to', 40)->nullable();                             // the provider that wrote the draft when it was not the feature's default (Chapter 19)
            $table->string('escalation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('triage_runs', function (Blueprint $table) {
            $table->dropColumn(['escalated_to', 'escalation_reason']);
        });
    }
};
