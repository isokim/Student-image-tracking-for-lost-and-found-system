<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_items', function (Blueprint $table) {
            $table->id();

            // Owner of the report. Policies compare this against the
            // authenticated user so student A cannot read student B's
            // report by changing the id in the URL.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('title');
            $table->text('description');
            $table->string('brand')->nullable();
            $table->string('colour')->nullable();

            // Free-text extra detail the owner adds. Visible to the owner and
            // to staff/admin, not in public listings.
            $table->text('additional_info')->nullable();

            $table->dateTime('lost_at'); // dateTime, not timestamp: MySQL gives the first NOT NULL TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, which would silently rewrite this date on every save.

            $table->enum('status', ['active', 'matched', 'resolved', 'closed'])
                ->default('active')
                ->index();

            // Set once the AI pipeline (analysis + embedding) has finished
            // for this item. Null means "still queued / not analysed".
            $table->timestamp('ai_processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Supports the common dashboard query: my reports, newest first.
            $table->index(['user_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index('lost_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_items');
    }
};
