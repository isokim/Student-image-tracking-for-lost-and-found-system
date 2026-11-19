<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Coarse role gate for whole route groups, e.g. the staff-only routes.
 *
 * This is the OUTER layer of authorisation, not the whole of it. It
 * answers "should this kind of user be anywhere near this section of the
 * API?" - which is the right question for the staff routes, where a
 * student has no business at all.
 *
 * It deliberately cannot answer "may this user touch this record?",
 * because middleware runs before the record is resolved. That question
 * belongs to the policies, and both layers apply: a route being behind
 * `role:staff` never excuses a controller from calling authorize().
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): SymfonyResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to do that.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $allowed = array_map(
            fn (string $role) => UserRole::from($role),
            $roles,
        );

        if (! in_array($user->role, $allowed, true)) {
            /**
             * A plain 403 with no detail about what the endpoint is or
             * who may use it. Telling a student "staff only" confirms
             * a staff area exists at that path.
             */
            return response()->json([
                'message' => 'You are not allowed to do that.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
