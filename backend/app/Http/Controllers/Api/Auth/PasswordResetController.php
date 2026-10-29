<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\PasswordResetRequestedNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * POST /api/forgot-password
     *
     * SECURITY: this always reports success, even when the email is not
     * registered. Saying "no account with that email" would let anyone
     * test which addresses exist. The rate limiter on the route stops it
     * being used to send mail in bulk.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        $requestingUser = User::query()
            ->where('email', $request->string('email')->toString())
            ->first();

        if ($requestingUser) {
            Notification::send(
                User::query()->where('role', UserRole::Admin->value)->get(),
                new PasswordResetRequestedNotification($requestingUser),
            );
        }

        return response()->json([
            'message' => 'If an account exists for that email, a reset link is on its way.',
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/reset-password
     */
    public function reset(Request $request): JsonResponse
    {
        // The React client sends passwordConfirmation; Laravel's
        // `confirmed` rule expects password_confirmation.
        if ($request->has('passwordConfirmation')) {
            $request->merge(['password_confirmation' => $request->input('passwordConfirmation')]);
        }

        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // The `hashed` cast on the model hashes this; a plaintext
                // password never reaches the database.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                /**
                 * Any session opened with the old password is now invalid.
                 * Without this, someone who reset a stolen account's
                 * password would not actually evict the thief.
                 */
                $user->tokens()->delete();

                event(new PasswordReset($user));

                $this->audit->log(
                    AuditLogger::PASSWORD_RESET,
                    $user,
                    'Password reset completed.',
                    $user,
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now sign in.',
        ], Response::HTTP_OK);
    }
}
