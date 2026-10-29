<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registration, sign-in and sign-out.
 *
 * Authentication is Sanctum in SPA mode: a normal Laravel session cookie,
 * not a bearer token. The React client calls /sanctum/csrf-cookie first,
 * then posts here with credentials included.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * POST /api/register - public student registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = new User([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'admission_number' => $request->validated('admissionNumber'),
            ]);

            /**
             * SECURITY: the role is assigned here by the server, never
             * taken from the request. Public registration always produces
             * a student. Staff and admin accounts are created by an
             * existing admin, or by `php artisan app:create-staff`.
             */
            $user->role = UserRole::Student;
            $user->status = UserStatus::Active;
            $user->save();

            return $user;
        });

        // Sends the verification email (MAIL_MAILER=log locally, so the
        // link appears in storage/logs/laravel.log).
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        $this->audit->log(AuditLogger::REGISTERED, $user, 'New student account registered.', $user);

        return (new UserResource($user->load('department')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/login
     *
     * Returns the bare user object, which is what authService.login()
     * expects - there is no envelope around it.
     */
    public function login(LoginRequest $request): UserResource
    {
        // Throws a 422 with a generic message on bad credentials, and
        // applies the 5-attempt rate limit.
        $request->authenticate();

        /**
         * SECURITY: regenerate the session id on login. Without this, an
         * attacker who fixed a victim's session id before sign-in would
         * still hold a valid session afterwards (session fixation).
         */
        $request->session()->regenerate();

        $user = $request->user();

        $this->audit->log(AuditLogger::LOGIN, $user, 'Signed in.');

        return new UserResource($user->load('department'));
    }

    /**
     * POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->audit->log(AuditLogger::LOGOUT, $request->user(), 'Signed out.');

        Auth::guard('web')->logout();

        // Both are needed: invalidate() drops the session data, and
        // regenerateToken() stops the old CSRF token being reused.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.'], Response::HTTP_OK);
    }

    /**
     * GET /api/user - the signed-in user.
     *
     * The React AuthContext calls this on page load to decide whether a
     * session is still valid, so it must 401 (not redirect) for guests.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('department'));
    }
}
