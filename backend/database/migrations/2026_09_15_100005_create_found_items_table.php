<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('found_items', function (Blueprint $table) {
            $table->id();

            // Whoever handed the item in - a student or a staff member.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('title');

            // Public description. Safe to show in listings and search.
            $table->text('description');

            // SECURITY: the detail only the true owner could know (a crack in
            // the screen, a name inside the bag, the phone lock screen). It is
            // never serialised into a public response - only the finder,
            // staff and admins can read it. Claim verification is checked
            // against this.
            $table->text('private_verification_info')->nullable();

            $table->string('brand')->nullable();
            $table->string('colour')->nullable();
            $table->text('additional_info')->nullable();

            $table->dateTime('found_at'); // dateTime, not timestamp: see the note in the lost_items migration.

            $table->enum('status', [
                'pending_verification',
                'verified',
                'claimed',
                'returned',
                'rejected',
            ])->default('pending_verification')->index();

            // Staff sign-off. A found item is not searchable by students
            // until a staff member has physically verified it.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('ai_processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index('found_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('found_items');
    }
};
