<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('prompt_version', 32)->nullable()->after('retrieved');        // the version that wrote this reply (Chapter 30)
            $t->string('experiment_variant', 32)->nullable()->after('prompt_version');
        });

        Schema::table('model_calls', function (Blueprint $t) {
            $t->string('variant', 32)->nullable()->after('prompt_version');
            $t->index(['feature', 'variant', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $t) {
            $t->dropIndex(['feature', 'variant', 'created_at']);
            $t->dropColumn('variant');
        });

        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn(['prompt_version', 'experiment_variant']);
        });
    }
};
