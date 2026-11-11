<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();    // one draft per invoice: the job is idempotent on this
            $t->foreignId('customer_id')->index();
            $t->string('prompt_version', 32);
            $t->text('body');
            $t->string('status', 16)->default('draft');                                 // draft | approved | rejected; nothing is ever sent from here
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_drafts');
    }
};
