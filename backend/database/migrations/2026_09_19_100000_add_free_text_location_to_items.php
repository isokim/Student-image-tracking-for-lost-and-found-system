<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Location is now typed by the person filing the report, not picked from
 * a fixed list.
 *
 * The locations table and location_id are kept rather than dropped:
 *
 *  - existing rows still point at a real Location, and staff/admin
 *    tooling and the search filter still work against them;
 *  - staff can still record a structured location when they want to;
 *  - the matching scorer gets a proper campus area when it has one.
 *
 * What changes is that location_id is now nullable and location_text
 * carries whatever the user typed. A report with free text is a
 * complete report, not a half-filled one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_items', function (Blueprint $table) {
            // 150 characters: long enough for "near the ATM by the main
            // gate" without turning into a second description field.
            $table->string('location_text', 150)->nullable()->after('location_id');
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->string('location_text', 150)->nullable()->after('location_id');
        });

        // change() keeps the existing foreign key constraint; it only
        // drops NOT NULL, so a report without a locations row is valid.
        Schema::table('lost_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->change();
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /**
         * Rolling back has to deal with rows that have no location_id,
         * which the old schema did not allow. They are backfilled with
         * the first active location so the NOT NULL constraint can be
         * restored instead of failing on real data.
         */
        $fallbackId = DB::table('locations')->where('is_active', true)->orderBy('id')->value('id');

        if ($fallbackId !== null) {
            DB::table('lost_items')->whereNull('location_id')->update(['location_id' => $fallbackId]);
            DB::table('found_items')->whereNull('location_id')->update(['location_id' => $fallbackId]);
        }

        Schema::table('lost_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable(false)->change();
            $table->dropColumn('location_text');
        });

        Schema::table('found_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable(false)->change();
            $table->dropColumn('location_text');
        });
    }
};
