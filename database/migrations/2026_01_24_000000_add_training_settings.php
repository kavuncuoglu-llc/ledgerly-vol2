<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('training_use')->default(false);                    // a tenant's admin turns this on under the product's terms; off until then
            $table->string('training_terms_version', 16)->nullable();
            $table->string('data_residency', 16)->nullable();                   // a region code: the tenant's data never leaves it, training data included
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('training_excluded')->default(false);               // an individual's opt-out inside an opted-in tenant
        });

        Schema::create('reply_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id');
            $table->foreignId('edited_by')->nullable();                         // the support agent
            $table->text('content');                                            // what the agent sent in place of the assistant's draft
            $table->timestamps();

            $table->unique('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_edits');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('training_excluded');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['training_use', 'training_terms_version', 'data_residency']);
        });
    }
};
