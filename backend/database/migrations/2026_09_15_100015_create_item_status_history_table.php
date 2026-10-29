<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every status transition an item goes through, so the detail page
        // can show a timeline and so disputes can be traced.
        Schema::create('item_status_history', function (Blueprint $table) {
            $table->id();
            $table->morphs('item');

            $table->string('from_status')->nullable(); // null on creation
            $table->string('to_status');

            // Nullable: some transitions are made by the system (a queued
            // job marking an item "matched"), not by a person.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_status_history');
    }
};
