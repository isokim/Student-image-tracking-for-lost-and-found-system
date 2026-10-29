<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Stops a suspended account from using an existing session.
 *
 * Checking status only at login would leave an already-signed-in user
 * working normally until their session expired - so an admin suspending
 * someone for abuse would not actually stop them. This runs on every
 * authenticated request, and logs the session out as it rejects it.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account has been suspended. Please contact the Lost & Found office.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
