<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The React app runs on a different origin (Vite, port 5173) from this
    | API (port 8000), so the browser treats every request as cross-origin.
    |
    | Because authentication is cookie-based (Sanctum SPA mode), two things
    | must line up or login will appear to work and then silently fail:
    |
    |   1. supports_credentials must be true, so the browser sends and
    |      accepts the session cookie.
    |   2. allowed_origins must list exact origins. The wildcard '*' is
    |      forbidden by the CORS spec when credentials are involved - the
    |      browser rejects the response. That is a security feature, not
    |      an inconvenience, so do not "fix" a CORS error by using '*'.
    |
    | sanctum/csrf-cookie must be in paths, because the SPA calls it before
    | every state-changing request.
    |
    */

    /**
     * broadcasting/auth is included because Broadcast::routes() registers
     * it at the root, outside api/*. Without it the SPA cannot authorise
     * private Reverb channels, and the tempting "fix" for that is to
     * widen allowed_origins - which is the thing to avoid.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'login', 'logout', 'register'],

    'allowed_methods' => ['*'],

    /**
     * The dev origins are added ONLY in the local environment.
     *
     * They used to be unconditional, which meant a deployed API would
     * still answer Access-Control-Allow-Origin: http://localhost:5173
     * with credentials allowed - so anything a victim's browser loaded
     * from a local dev server on that port could make credentialed reads
     * of this API and exfiltrate their claims and private item notes.
     */
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [env('FRONTEND_URL')],
        env('APP_ENV') === 'local'
            // Vite may bind either hostname depending on how it was started.
            ? ['http://localhost:5173', 'http://127.0.0.1:5173']
            : [],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
