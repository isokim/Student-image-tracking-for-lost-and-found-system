<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The second "controlled process" for provisioning privileged accounts,
 * alongside the admin API.
 *
 * It exists for the bootstrap problem: creating the very first admin,
 * when there is no admin logged in to use the admin endpoint. Running it
 * requires shell access to the server, which is its own authorisation.
 *
 *   php artisan app:create-staff
 *   php artisan app:create-staff --role=admin --email=me@uni.ac.ke
 */
class CreateStaffUser extends Command
{
    protected $signature = 'app:create-staff
                            {--name= : Full name}
                            {--email= : Email address}
                            {--role=staff : staff or admin}
                            {--department= : Department name}
                            {--password= : Password. Prefer omitting this and being prompted, so it stays out of your shell history}';

    protected $description = 'Create a staff or admin account (students register themselves).';

    public function handle(AuditLogger $audit): int
    {
        /**
         * Laravel Prompts block waiting on stdin even under
         * --no-interaction, so anything prompted has to be guarded by
         * this check. Without it the command hangs forever in a script
         * or CI run instead of failing with a usable message.
         */
        $interactive = $this->input->isInteractive();

        $name = $this->option('name') ?: ($interactive ? text('Full name', required: true) : null);
        $email = $this->option('email') ?: ($interactive ? text('Email address', required: true) : null);

        $role = $this->option('role')
            ?: ($interactive ? select('Role', ['staff', 'admin'], default: 'staff') : 'staff');

        /**
         * "student" is refused here on purpose. A student account created
         * this way would have no admission number, which is how students
         * are identified everywhere else in the system.
         */
        if (! in_array($role, ['staff', 'admin'], true)) {
            $this->error('Role must be "staff" or "admin". Students register through the app.');

            return self::FAILURE;
        }

        $departmentName = $this->option('department')
            ?: ($interactive ? text('Department', default: 'Student Affairs', required: true) : 'Student Affairs');

        // Prompted by preference, so the password stays out of the shell
        // history. The option exists so the command can still be scripted.
        $plainPassword = $this->option('password')
            ?: ($interactive ? password('Password (min 8 chars, letters and numbers)', required: true) : null);

        if (! $name || ! $email || ! $plainPassword) {
            $this->error('Non-interactive use requires --name, --email and --password.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
        ], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $department = Department::firstOrCreate(['name' => $departmentName]);

        $user = new User([
            'name' => $name,
            'email' => $email,
            // The model's `hashed` cast hashes this on assignment.
            'password' => $plainPassword,
            'department_id' => $department->id,
        ]);

        // Assigned as a property, because role is not fillable.
        $user->role = UserRole::from($role);
        $user->status = UserStatus::Active;

        // Provisioned in person, so there is no email to verify.
        $user->email_verified_at = now();
        $user->save();

        $audit->log(
            AuditLogger::STAFF_CREATED,
            $user,
            "Created {$role} account for {$email} via the console.",
            $user,
        );

        $this->info("Created {$role} account: {$email}");

        return self::SUCCESS;
    }
}
