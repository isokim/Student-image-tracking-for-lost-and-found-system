<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions the first admin and a staff member.
 *
 * This is one of the two "controlled processes" the brief asks for -
 * nobody can register as staff or admin through the public API. The other
 * is `php artisan app:create-staff`, for adding people later.
 *
 * Note `role` is assigned as a property rather than passed to create():
 * it is not in the model's $fillable, precisely so it cannot be set by
 * mass assignment from a web request.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $itServices = Department::firstOrCreate(['name' => 'IT Services']);
        $studentAffairs = Department::firstOrCreate(['name' => 'Student Affairs']);

        $admin = User::firstOrNew(['email' => 'admin@lostandfound.test']);
        $admin->fill([
            'name' => 'System Administrator',
            'password' => 'password',
            'department_id' => $itServices->id,
        ]);
        $admin->role = UserRole::Admin;
        $admin->email_verified_at = now();
        $admin->save();

        $staff = User::firstOrNew(['email' => 'staff@lostandfound.test']);
        $staff->fill([
            'name' => 'Front Desk Staff',
            'password' => 'password',
            'department_id' => $studentAffairs->id,
        ]);
        $staff->role = UserRole::Staff;
        $staff->email_verified_at = now();
        $staff->save();

        $this->command?->info('Seeded admin@lostandfound.test and staff@lostandfound.test (password: "password").');
        $this->command?->warn('These are local development accounts only. Change them before any real deployment.');
    }
}
