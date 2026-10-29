<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_sign_in_with_correct_credentials(): void
    {
        $user = User::factory()->student()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('id', (string) $user->id)
            ->assertJsonPath('email', 'amina@student.test')
            ->assertJsonPath('role', 'student');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The reported bug: a student signed in on a shared machine could
     * not sign in as anyone else.
     *
     * /api/login used to carry the `guest` middleware, so a request that
     * already had a valid session was answered with a 302 to "/" instead
     * of being authenticated. The React app never saw the redirect as a
     * response it could read - the browser blocked it as a cross-origin
     * failure and the user was told "Can't reach the server", which
     * pointed at the network rather than at the real cause.
     *
     * Asserting the status is the important half: a redirect is exactly
     * what must not happen here.
     */
    public function test_a_signed_in_user_can_sign_in_as_someone_else(): void
    {
        $student = User::factory()->student()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        $admin = User::factory()->admin()->create([
            'email' => 'boss@staff.test',
            'password' => 'Password123',
        ]);

        // Signed in as the student first...
        $this->actingAs($student);

        $response = $this->postJson('/api/login', [
            'email' => 'boss@staff.test',
            'password' => 'Password123',
        ]);

        $this->assertFalse(
            $response->isRedirect(),
            'Signing in must return JSON, never a redirect to the dashboard.'
        );

        $response->assertOk()->assertJsonPath('id', (string) $admin->id);

        // ...and now authenticated as the admin. Without the session id
        // being regenerated here, the old student session would survive.
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * Signing in again as yourself must also work. An SPA that reloads
     * and re-authenticates should not have to know it already holds a
     * session.
     */
    public function test_signing_in_again_as_the_same_user_is_not_refused(): void
    {
        $user = User::factory()->student()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        $this->actingAs($user);

        $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('id', (string) $user->id);
    }

    public function test_the_response_uses_the_camelcase_shape_the_react_app_expects(): void
    {
        User::factory()->student()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
            'admission_number' => 'BIT/2024/0142',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ])->assertOk();

        // Matches the User interface in src/types/index.ts.
        $response->assertJsonStructure([
            'id', 'name', 'email', 'role', 'admissionNumber', 'emailVerifiedAt', 'createdAt',
        ]);

        // Ids are strings, because the TypeScript type declares `id: string`.
        $this->assertIsString($response->json('id'));

        // And there is no {success, message, data} envelope.
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_sign_in_fails_with_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'WrongPassword',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    /**
     * SECURITY: the error for an unknown email must be identical to the
     * error for a wrong password, or the endpoint becomes a way to find
     * out which email addresses have accounts.
     */
    public function test_an_unknown_email_gives_the_same_error_as_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'WrongPassword',
        ])->assertStatus(422);

        $unknownEmail = $this->postJson('/api/login', [
            'email' => 'nobody@student.test',
            'password' => 'WrongPassword',
        ])->assertStatus(422);

        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
    }

    public function test_a_failed_sign_in_is_written_to_the_audit_log(): void
    {
        User::factory()->create(['email' => 'amina@student.test', 'password' => 'Password123']);

        $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'WrongPassword',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::LOGIN_FAILED]);
    }

    public function test_sign_in_is_rate_limited_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'amina@student.test', 'password' => 'Password123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'amina@student.test',
                'password' => 'WrongPassword',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'WrongPassword',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'Too many sign-in attempts',
            $response->json('errors.email.0'),
        );
    }

    /**
     * The lockout is keyed on email+IP, so locking one account must not
     * lock a different account from the same machine.
     */
    public function test_the_lockout_does_not_affect_a_different_account(): void
    {
        User::factory()->create(['email' => 'amina@student.test', 'password' => 'Password123']);
        $other = User::factory()->create(['email' => 'brian@student.test', 'password' => 'Password123']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', [
                'email' => 'amina@student.test',
                'password' => 'WrongPassword',
            ]);
        }

        $this->postJson('/api/login', [
            'email' => 'brian@student.test',
            'password' => 'Password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($other);
    }

    public function test_a_signed_in_user_can_read_their_own_profile(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', (string) $user->id);
    }

    public function test_a_guest_cannot_read_the_profile_endpoint(): void
    {
        // 401 JSON, never a redirect to a login page - the React
        // AuthContext relies on this to detect an expired session.
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('message', 'You must be signed in to do that.');
    }

    public function test_a_user_can_sign_out(): void
    {
        $user = User::factory()->create([
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ]);

        /**
         * Signing in through the endpoint rather than with actingAs(),
         * because only a real sign-in writes the session - and the session
         * is exactly what logout has to clear.
         */
        $this->postJson('/api/login', [
            'email' => 'amina@student.test',
            'password' => 'Password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/logout')->assertOk();

        /**
         * Asserted against the 'web' (session) guard specifically.
         *
         * A follow-up request to a protected route would NOT prove
         * anything here: auth:sanctum keeps the resolved user on the
         * sanctum guard inside the same application instance, so it would
         * answer 200 even though the session is gone. A browser makes each
         * request in a fresh process and would be rejected. The session
         * guard being empty is the real, checkable behaviour.
         */
        $this->assertGuest('web');

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::LOGOUT]);
    }

    public function test_a_guest_cannot_sign_out(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Account status
    // -----------------------------------------------------------------

    public function test_a_suspended_user_is_blocked_on_every_request_not_just_at_login(): void
    {
        $suspended = User::factory()->suspended()->create();

        // actingAs bypasses the login check entirely, which is exactly the
        // scenario that matters: a session opened *before* the suspension.
        $this->actingAs($suspended)
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been suspended. Please contact the Lost & Found office.');
    }

    public function test_an_active_user_is_not_blocked(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/user')
            ->assertOk();
    }
}
