<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately does NOT store the vector. FAISS owns the vectors;
        // MySQL only stores the reference needed to ask FAISS about this
        // item. That keeps Laravel decoupled from the AI implementation,
        // so the Python service can move to the cloud untouched.
        Schema::create('item_embeddings', function (Blueprint $table) {
            $table->id();
            $table->morphs('item');

            // The id FAISS knows this item by.
            $table->string('embedding_reference')->index();

            $table->string('model_name');
            $table->string('model_version');
            $table->unsignedSmallInteger('dimensions')->nullable();

            $table->timestamps();

            // One embedding per item per model. Short name because MySQL
            // caps index names at 64 characters.
            $table->unique(['item_type', 'item_id', 'model_name'], 'item_embeddings_item_model_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_embeddings');
    }
};
