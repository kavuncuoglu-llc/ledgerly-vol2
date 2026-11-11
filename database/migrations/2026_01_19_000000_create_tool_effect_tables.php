<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('billing_email_pending')->nullable()->after('email');       // Chapter 33: set by change_billing_email, applied only after the old address confirms
        });

        Schema::create('payment_retries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('queued');                            // the payment processor is fictional: this is the request it would read
            $table->timestamps();
        });

        Schema::create('invoice_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('reason', 200);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_credits');
        Schema::dropIfExists('payment_retries');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('billing_email_pending');
        });
    }
};
