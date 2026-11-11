<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->json('guard')->nullable()->after('retrieved');     // Chapter 32: what the answer checks found, [{rule, hosts?}]; never the content they found it in
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn('guard');
        });
    }
};
