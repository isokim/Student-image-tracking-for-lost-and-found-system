<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand is gone from the product, not just from the form.
 *
 * It was removed from both report forms first, then from validation and
 * the services. This drops what is left: the columns nothing writes to
 * any more, and the match component that scored them. A nullable column
 * that no code path can fill is just a trap for the next person reading
 * the schema.
 *
 * The AI's own `detected_brand` on item_ai_analysis is NOT dropped - that
 * is the vision model's observation about a photo, not a field the
 * reporter fills in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_items', function (Blueprint $table) {
            $table->dropColumn('brand');
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->dropColumn('brand');
        });

        Schema::table('item_matches', function (Blueprint $table) {
            $table->dropColumn('brand_score');
        });
    }

    public function down(): void
    {
        Schema::table('lost_items', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('description');
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('private_verification_info');
        });

        // Matches scored by the old code are left at 0; the score cannot
        // be reconstructed, and 0 is at least honest about that.
        Schema::table('item_matches', function (Blueprint $table) {
            $table->decimal('brand_score', 5, 4)->default(0)->after('colour_score');
        });
    }
};
