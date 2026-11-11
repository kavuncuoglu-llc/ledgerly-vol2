<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erasure_requests', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 64);                         // "user:12" or "customer:8812": ids, never a name or an address
            $table->string('reason', 255)->nullable();
            $table->json('deleted');                               // what user:forget removed, by copy
            $table->json('anonymized');                            // what it kept for cost history, with the link to the person cut
            $table->json('expiring');                              // what it could not touch, and when each goes by itself
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_requests');
    }
};
