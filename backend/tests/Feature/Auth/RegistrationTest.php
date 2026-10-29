<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amina Yusuf',
            'email' => 'amina@student.test',
            'admissionNumber' => 'BIT/2024/0142',
            'password' => 'Password123',
            'passwordConfirmation' => 'Password123',
        ], $overrides);
    }

    public function test_a_student_can_register(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated()
            // The response is the bare user object - no {success, data}
            // envelope - because that is what authService.register() reads.
            ->assertJsonPath('name', 'Amina Yusuf')
            ->assertJsonPath('email', 'amina@student.test')
            ->assertJsonPath('role', 'student')
            ->assertJsonPath('admissionNumber', 'BIT/2024/0142');

        $this->assertDatabaseHas('users', [
            'email' => 'amina@student.test',
            'role' => 'student',
        ]);

        // Registration signs the user in, which is what the React
        // AuthContext expects.
        $this->assertAuthenticated();
    }

    public function test_the_password_is_hashed_and_never_stored_in_plaintext(): void
    {
        $this->postJson('/api/register', $this->validPayload())->assertCreated();

        $user = User::where('email', 'amina@student.test')->firstOrFail();

        $this->assertNotSame('Password123', $user->password);
        $this->assertTrue(Hash::check('Password123', $user->password));
    }

    public function test_the_password_is_never_returned_in_the_response(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated();
        $this->assertArrayNotHasKey('password', $response->json());
    }

    /**
     * SECURITY: the headline privilege-escalation test.
     */
    public function test_a_user_cannot_make_themselves_an_admin_during_registration(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'role' => 'admin',
        ]))->assertCreated();

        $user = User::where('email', 'amina@student.test')->firstOrFail();

        $this->assertSame(UserRole::Student, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_a_user_cannot_make_themselves_staff_during_registration(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'role' => 'staff',
        ]))->assertCreated();

        $this->assertSame(
            UserRole::Student,
            User::where('email', 'amina@student.test')->firstOrFail()->role
        );
    }

    public function test_a_user_cannot_activate_their_own_account_status_or_verify_their_own_email(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'status' => 'suspended',
            'email_verified_at' => now()->toIso8601String(),
        ]))->assertCreated();

        $user = User::where('email', 'amina@student.test')->firstOrFail();

        // status stays at the server-assigned default...
        $this->assertFalse($user->isSuspended());
        // ...and the email is genuinely unverified until the link is used.
        $this->assertNull($user->email_verified_at);
    }

    public function test_registration_requires_all_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'admissionNumber', 'password']);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'amina@student.test']);

        $this->postJson('/api/register', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_a_duplicate_admission_number(): void
    {
        User::factory()->create(['admission_number' => 'BIT/2024/0142']);

        $this->postJson('/api/register', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('admissionNumber');
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'password' => 'abc',
            'passwordConfirmation' => 'abc',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'passwordConfirmation' => 'SomethingElse123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_sends_an_email_verification_notification(): void
    {
        Event::fake();

        $this->postJson('/api/register', $this->validPayload())->assertCreated();

        Event::assertDispatched(Registered::class);
    }

    public function test_registration_is_written_to_the_audit_log(): void
    {
        $this->postJson('/api/register', $this->validPayload())->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::REGISTERED,
            'entity_type' => 'user',
        ]);

        $this->assertNotNull(AuditLog::first()->ip_address);
    }

    public function test_registration_is_rate_limited(): void
    {
        /**
         * The route allows 6 attempts per minute per IP.
         *
         * Each attempt here is deliberately invalid. Throttling runs
         * before validation, so a failed attempt still counts - which is
         * the case that matters, since someone creating junk accounts or
         * probing the endpoint will not send valid data either. Using
         * valid payloads would also sign the user in on the first call
         * and the rest would hit the `guest` middleware instead.
         */
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/register', [])->assertStatus(422);
        }

        $this->postJson('/api/register', [])->assertStatus(429);
    }
}
