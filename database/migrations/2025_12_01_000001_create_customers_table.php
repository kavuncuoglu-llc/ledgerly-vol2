<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name');
            $table->string('email')->unique();
            $table->string('plan', 16)->default('starter');
            $table->string('status', 16)->default('active');
            $table->char('currency', 3)->default('USD');
            $table->char('country', 2)->default('US');
            $table->string('timezone', 64)->default('America/New_York');
            $table->unsignedSmallInteger('seats')->default(1);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::dropIfExists('customers');
    }
};
