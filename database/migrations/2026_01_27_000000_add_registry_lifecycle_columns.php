<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_registry', function (Blueprint $t) {
            $t->unsignedTinyInteger('percent')->nullable();               // the share of customers a canary serves
            $t->json('tools')->nullable();                                // llama.cpp, peft, mlx-lm: the pinned tools that touched the artifacts (Chapter 44, Topic 8)
            $t->json('signatures')->nullable();                           // artifact key => hash, signature, signer
            $t->json('served_on')->nullable();                            // server name => what serve:fine-tune loaded there
            $t->timestamp('promoted_at')->nullable();
            $t->timestamp('warm_until')->nullable();                      // a previous version stays loaded until then
            $t->timestamp('retired_at')->nullable();
            $t->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_registry', function (Blueprint $t) {
            $t->dropColumn(['percent', 'tools', 'signatures', 'served_on', 'promoted_at', 'warm_until', 'retired_at', 'archived_at']);
        });
    }
};
