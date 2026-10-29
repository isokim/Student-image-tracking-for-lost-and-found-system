<?php

namespace Database\Factories;

use App\Enums\ClaimStatus;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Claim> */
class ClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'found_item_id' => FoundItem::factory()->verified(),
            'claimant_id' => User::factory()->student(),
            'identifying_information' => fake()->sentence(10),
            'status' => ClaimStatus::PendingReview,
            'submitted_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Draft,
            'submitted_at' => null,
        ]);
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Approved,
            'reviewed_by' => $reviewer?->id ?? User::factory()->staff(),
            'reviewed_at' => now(),
        ]);
    }
}
