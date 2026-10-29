<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the vision model *thinks* it saw. Advisory only: it never
        // overwrites what the human typed, it is stored alongside it and
        // used to improve matching and search.
        Schema::create('item_ai_analysis', function (Blueprint $table) {
            $table->id();
            $table->morphs('item');

            $table->string('detected_category')->nullable();
            $table->string('detected_colour')->nullable();
            $table->string('detected_brand')->nullable();

            // Free-form list of features the model extracted, e.g.
            // ["cracked screen", "blue case", "sticker on back"].
            $table->json('detected_features')->nullable();

            $table->text('generated_description')->nullable();

            // Which model produced this. Stored so results can be compared
            // or invalidated when the model changes.
            $table->string('model_name');
            $table->string('model_version');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_ai_analysis');
    }
};
