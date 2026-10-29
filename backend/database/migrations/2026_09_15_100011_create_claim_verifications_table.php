<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();

            $table->string('question');

            // What the claimant said.
            $table->text('answer');

            // SECURITY: the correct answer, taken from the found item's
            // private verification info. This column must NEVER reach the
            // claimant - if it did, anyone could claim anything. Only
            // reviewing staff and admins see it.
            $table->text('expected_answer')->nullable();

            // Staff's judgement of the answer.
            $table->enum('result', ['pending', 'pass', 'fail'])->default('pending')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_verifications');
    }
};
