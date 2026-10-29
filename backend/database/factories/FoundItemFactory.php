<?php

namespace Database\Factories;

use App\Enums\FoundItemStatus;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FoundItem> */
class FoundItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'category_id' => Category::factory(),
            // Free text, as the report form collects it.
            'location_text' => fake()->randomElement([
                'Near the library entrance', 'Student cafeteria', 'Lecture Theatre 3', 'Main gate',
            ]),
            'title' => fake()->randomElement(['Black smartphone', 'Blue rucksack', 'Set of keys', 'Silver laptop']),
            'description' => fake()->sentence(12),
            'private_verification_info' => 'Secret detail: '.fake()->sentence(6),
            'colour' => fake()->randomElement(['black', 'blue', 'silver', 'red']),
            'found_at' => fake()->dateTimeBetween('-30 days', 'now'),

            // Default is the real default: not yet checked by staff, and
            // therefore not visible to students.
            'status' => FoundItemStatus::PendingVerification,
        ];
    }

    public function for_user(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn () => [
            'status' => FoundItemStatus::Verified,
            'verified_by' => $verifier?->id ?? User::factory()->staff(),
            'verified_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => ['status' => FoundItemStatus::Returned]);
    }
}
