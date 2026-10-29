<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Email verification.
 *
 * The verification link is opened in a browser, not by the SPA's HTTP
 * client, so verify() redirects back to the React app rather than
 * returning JSON.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * GET /api/verify-email/{id}/{hash}
     *
     * The route carries a signed-URL signature, so the id and hash cannot
     * be tampered with or forged - that is what authorises this action,
     * which is why the route itself does not require a login.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // Confirms the hash really was derived from this user's email.
        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect($this->frontend('/login?verified=0'));
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($this->frontend('/login?verified=1'));
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        $this->audit->log(AuditLogger::EMAIL_VERIFIED, $user, 'Email address verified.', $user);

        return redirect($this->frontend('/login?verified=1'));
    }

    /**
     * POST /api/email/verification-notification - resend the link.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is already verified.',
            ], Response::HTTP_OK);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'A new verification link has been sent to your email address.',
        ], Response::HTTP_OK);
    }

    private function frontend(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').$path;
    }
}
