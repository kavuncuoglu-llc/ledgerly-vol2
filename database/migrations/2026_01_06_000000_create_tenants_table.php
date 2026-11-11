<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('customer_id')->nullable()->unique()->constrained()->cascadeOnDelete();   // a tenant is a customer account
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();   // null for Ledgerly staff
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
        });

        Schema::dropIfExists('tenants');
    }
};
