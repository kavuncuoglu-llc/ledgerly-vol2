<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitive_routings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('conversation_id')->nullable()->index();
            $t->unsignedBigInteger('message_id')->nullable()->index();
            $t->string('category', 24);                          // safety | harassment | out_of_scope | in_scope
            $t->string('subcategory', 24);                       // distress, legal, tax, ... never the message
            $t->string('decision', 24);                          // person_priority | person | decline_and_person | false_refusal
            $t->string('source', 16)->default('patterns');       // patterns | classifier | detector
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['message_id', 'decision']);              // one row per message and decision, however often a job retries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_routings');
    }
};
