<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_reviews', function (Blueprint $t) {
            $t->id();
            $t->string('draft_type', 32);                              // reminder_draft | triage_reply: what the agent reviewed
            $t->unsignedBigInteger('draft_id');
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at');
            $t->unsignedInteger('edit_distance')->default(0);          // characters changed between the draft and what was sent: 0 is "sent unedited"
            $t->unsignedInteger('review_seconds')->default(0);
            $t->boolean('audited')->default(false);                    // a senior agent read it in the weekly audit
            $t->boolean('audit_found_error')->default(false);
            $t->timestamps();
            $t->index('sent_at');
            $t->unique(['draft_type', 'draft_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_reviews');
    }
};
