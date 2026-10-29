<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // Default to the least-privileged role, so a test that forgets
            // to say which role it wants gets the safest one.
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'admission_number' => fake()->unique()->bothify('BIT/20##/####'),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function student(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Student,
            'department_id' => null,
        ]);
    }

    public function staff(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Staff,
            'admission_number' => null,
            'department_id' => Department::factory(),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
            'admission_number' => null,
            'department_id' => Department::factory(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
