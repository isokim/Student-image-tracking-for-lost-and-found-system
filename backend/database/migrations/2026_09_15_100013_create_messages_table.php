<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Direct messages between a claimant and reviewing staff, scoped to
        // a claim ("can you bring your student ID to the office?").
        // The table exists because the brief asks for it; no endpoints are
        // exposed yet because the React app has no messaging screen.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
