<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // "Main Library, 2nd Floor"
            $table->string('campus_area');     // "Main Campus"
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['name', 'campus_area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
