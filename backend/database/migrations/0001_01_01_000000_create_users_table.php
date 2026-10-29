<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Authorisation lives here and nowhere else. The frontend reads
            // this to decide which dashboard to show, but every protected
            // endpoint re-checks it server-side via policies/gates.
            $table->enum('role', ['student', 'staff', 'admin'])->default('student')->index();

            // Students are identified by admission number; staff/admin by
            // department. Both are nullable because neither applies to
            // every role.
            $table->string('admission_number')->nullable()->unique();

            // FK constraint is added by the create_departments_table
            // migration, because that table is created after this one.
            $table->unsignedBigInteger('department_id')->nullable()->index();

            $table->string('avatar_path')->nullable();

            // A suspended user keeps their records but cannot authenticate.
            $table->enum('status', ['active', 'suspended'])->default('active')->index();
            $table->timestamp('suspended_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Sanctum SPA authentication is cookie/session based, so this table
        // is the actual session store (SESSION_DRIVER=database).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
