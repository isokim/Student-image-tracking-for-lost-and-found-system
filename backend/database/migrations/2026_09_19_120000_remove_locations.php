<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the admin-managed locations list.
 *
 * The list existed to give report forms a dropdown. Now that the person
 * filing a report types the place themselves ("near the ATM by the main
 * gate"), a fixed list is not just redundant - it is worse, because a
 * dropdown can only ever offer the places somebody thought of in advance.
 *
 * location_text on both item tables is what survives, and it is what the
 * forms, search and the matcher now use.
 *
 * Dropping the columns also drops the foreign keys; the table cannot be
 * dropped while something still references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('locations');
    }

    public function down(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('campus_area');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['name', 'campus_area']);
        });

        /**
         * Nullable, not required. Going back cannot invent a locations
         * row for every free-text place that was typed while this was
         * removed, so the columns come back empty and the typed text
         * stays authoritative.
         */
        Schema::table('lost_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('category_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('category_id')
                ->constrained()->restrictOnDelete();
        });
    }
};
