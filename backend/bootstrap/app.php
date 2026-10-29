<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Sanctum SPA (cookie) authentication.
         *
         * This tells Laravel: when a request arrives from one of the
         * SANCTUM_STATEFUL_DOMAINS origins, run it through the web
         * middleware stack first - session, cookie encryption and CSRF -
         * so the SPA authenticates with a normal session cookie instead of
         * a bearer token. Without this line every request looks like a
         * guest, no matter how many times the user logs in.
         */
        $middleware->statefulApi();

        /**
         * A baseline rate limit on EVERY api route.
         *
         * Laravel 11/12 does not apply one by default - without this line
         * only the handful of routes carrying an explicit `throttle:`
         * were limited, and everything else was unbounded. That mattered
         * most for two things: /api/item-images/{id}, which would let a
         * signed-in student enumerate and bulk-download every photo on
         * the system (these include handed-in student IDs), and the
         * 404-vs-403 design on detail endpoints, which assumes probing
         * ids is expensive.
         *
         * The limiter itself is defined in AppServiceProvider.
         */
        $middleware->throttleApi('api');

        /**
         * A suspended account must stop working immediately, not at the
         * next login. This runs on every authenticated request.
         */
        $middleware->alias([
            'active' => EnsureUserIsActive::class,

            /**
             * Coarse role gate for route groups, e.g. `role:admin`.
             * The outer layer only - policies still run per record
             * inside the controllers.
             */
            'role' => EnsureUserHasRole::class,
        ]);

        // This is a JSON API; never redirect a browser to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * One consistent error shape for the whole API.
         *
         * The React client's normalizeApiError() reads `message` and the
         * optional `errors` map, so every failure - validation, auth,
         * missing record, server fault - is rendered the same way here.
         *
         * Note what is deliberately NOT included: exception classes, file
         * paths and stack traces. Leaking those tells an attacker about
         * the internals. Full detail still goes to storage/logs.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'You must be signed in to do that.',
            ], Response::HTTP_UNAUTHORIZED);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            /**
             * Honours the status the policy chose rather than forcing 403.
             *
             * Policies use Response::denyAsNotFound() for records the
             * caller has no business knowing exist - another student's
             * claim, an unverified found item. That carries a 404, and
             * hardcoding 403 here would undo it and confirm the record
             * exists, which is the exact leak the policy was avoiding.
             */
            $status = $e->status() ?? Response::HTTP_FORBIDDEN;

            $message = $status === Response::HTTP_NOT_FOUND
                ? 'That record could not be found.'
                : ($e->getMessage() ?: 'You are not allowed to do that.');

            return response()->json(['message' => $message], $status);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return response()->json([
                'message' => 'That record could not be found.',
            ], Response::HTTP_NOT_FOUND);
        });

        /**
         * Everything that carries its own HTTP status: 404, 405, 413
         * (upload too large), 419 (expired CSRF token), 429 (rate limited).
         *
         * This has to catch the interface rather than each exception class.
         * Laravel converts a TokenMismatchException into a plain
         * HttpException before it reaches here, so a handler typed against
         * TokenMismatchException never fires - and the request falls
         * through to the default renderer, which with APP_DEBUG=true
         * returns a full stack trace including absolute file paths.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match ($status) {
                419 => 'Your session has expired. Please refresh the page and try again.',
                Response::HTTP_NOT_FOUND => 'That record could not be found.',
                Response::HTTP_METHOD_NOT_ALLOWED => 'That action is not supported on this endpoint.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'That file is too large to upload.',
                Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests. Please slow down and try again shortly.',
                default => $e->getMessage() ?: 'The request could not be completed.',
            };

            return response()->json(['message' => $message], $status, $e->getHeaders());
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // In local development, keep the real message so the student
            // building this can debug. In production, say nothing useful.
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Something went wrong. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
