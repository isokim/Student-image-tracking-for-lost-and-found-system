<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Student Affairs', 'IT Services', 'Security', 'Library Services',
                'Registry', 'Facilities', 'Academics', 'Finance',
            ]).' '.fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
