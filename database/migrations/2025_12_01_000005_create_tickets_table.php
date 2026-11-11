<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('summary')->nullable();
            $table->string('category', 16)->nullable()->index();
            $table->string('status', 16)->default('open')->index();
            $table->string('source', 16)->default('email');
            $table->string('invoice_number')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER SEQUENCE tickets_id_seq RESTART WITH 10401');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
