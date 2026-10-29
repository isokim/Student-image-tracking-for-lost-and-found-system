<?php

namespace Database\Factories;

use App\Enums\LostItemStatus;
use App\Models\Category;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LostItem> */
class LostItemFactory extends Factory
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
            'title' => fake()->randomElement(['Black iPhone 13', 'Blue backpack', 'Student ID card', 'Silver laptop']),
            'description' => fake()->sentence(12),
            'colour' => fake()->randomElement(['black', 'blue', 'silver', 'red']),
            'lost_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => LostItemStatus::Active,
        ];
    }

    public function for_user(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => LostItemStatus::Closed]);
    }
}
