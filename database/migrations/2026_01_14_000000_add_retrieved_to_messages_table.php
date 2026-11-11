<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->json('retrieved')->nullable()->after('question');   // [{label, eval_label, id, chunk_key}]: the passages the model was shown for this reply (Chapter 29)
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn('retrieved');
        });
    }
};
