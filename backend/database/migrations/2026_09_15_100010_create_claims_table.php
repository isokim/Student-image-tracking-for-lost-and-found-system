<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('found_item_id')->constrained()->cascadeOnDelete();

            // Optional: the claimant's own lost report, and the AI match that
            // prompted the claim. Both are nullable because a student can
            // claim an item they never reported as lost.
            $table->foreignId('lost_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_match_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('claimant_id')->constrained('users')->cascadeOnDelete();

            $table->enum('status', [
                'draft',
                'pending_review',
                'approved',
                'rejected',
                'completed',
            ])->default('draft')->index();

            // The claimant's description of the item / proof of ownership.
            // Private: readable by the claimant, reviewing staff and admins.
            $table->text('identifying_information');

            // Staff's note on the decision. Shown to the claimant.
            $table->text('review_notes')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['claimant_id', 'status']);
            $table->index(['found_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
