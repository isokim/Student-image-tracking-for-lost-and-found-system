<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => ucfirst($name),
            'is_active' => true,
        ];
    }

    /** Use a real slug from the frontend's ItemCategory union. */
    public function electronics(): static
    {
        return $this->state(fn () => ['slug' => 'electronics', 'name' => 'Electronics']);
    }
}
