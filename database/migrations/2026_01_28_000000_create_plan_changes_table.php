<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('from_plan', 16)->nullable();                     // null on the first row: the plan the account signed up on
            $t->string('to_plan', 16);
            $t->string('change_type', 16);                               // signup | upgrade | downgrade
            $t->string('via', 24);                                       // self_serve | sales | renewal | billing_system
            $t->string('note')->nullable();                              // free text for staff; no tool may read it
            $t->timestamp('changed_at');
            $t->timestamps();
            $t->index(['customer_id', 'changed_at']);
        });

        $this->grantToToolRole();
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_changes');
    }

    private function grantToToolRole(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT SELECT (id, customer_id, from_plan, to_plan, change_type, via, changed_at)
              ON plan_changes TO ledgerly_tool_read;
        SQL);                                                                     // Chapter 49: the columns look_up_plan_change shows the model, and not the staff note
    }
};
