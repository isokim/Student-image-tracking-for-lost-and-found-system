<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only record of who did what. Never updated or deleted by
        // application code - only admins can read it.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: a failed login has no authenticated user yet.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action')->index();          // "claim.approved"
            $table->string('entity_type')->nullable();  // "found_item"
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('description')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
