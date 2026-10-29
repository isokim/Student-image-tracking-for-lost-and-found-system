<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lost_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('found_item_id')->constrained()->cascadeOnDelete();

            // Each component of the hybrid score, kept separately so the UI
            // can explain *why* something matched and so the weights can be
            // retuned without recomputing the parts. All are 0.0000-1.0000;
            // the API multiplies by 100 for display.
            $table->decimal('image_score', 5, 4)->nullable();  // null when no photo to compare
            $table->decimal('text_score', 5, 4)->default(0);
            $table->decimal('category_score', 5, 4)->default(0);
            $table->decimal('colour_score', 5, 4)->default(0);
            $table->decimal('brand_score', 5, 4)->default(0);
            $table->decimal('location_score', 5, 4)->default(0);
            $table->decimal('time_score', 5, 4)->default(0);

            // Weighted total. Indexed because matches are always read
            // strongest-first.
            $table->decimal('overall_score', 5, 4)->default(0)->index();

            // Deliberately never "guaranteed" - the system only ever
            // suggests. A human confirms by approving a claim.
            $table->enum('confidence', ['possible', 'likely'])->default('possible');

            $table->enum('status', ['pending_review', 'dismissed', 'claim_started'])
                ->default('pending_review')
                ->index();

            $table->timestamp('dismissed_at')->nullable();

            // Which weight profile produced this score, so old matches are
            // interpretable after the weights change.
            $table->string('scoring_version')->default('v1');

            $table->timestamps();

            // The same pair must never be suggested twice.
            $table->unique(['lost_item_id', 'found_item_id'], 'item_matches_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_matches');
    }
};
