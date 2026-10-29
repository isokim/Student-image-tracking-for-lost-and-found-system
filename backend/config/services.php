<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Local AI service (Python FastAPI)
    |--------------------------------------------------------------------------
    |
    | Laravel talks to the AI service over plain HTTP and knows nothing
    | about Ollama, SigLIP or FAISS - that is the point. Repointing `url`
    | at a cloud host is the only change needed to move the AI service off
    | this machine, and the React app never learns the difference because
    | it only ever talks to Laravel.
    |
    | `enabled` = false makes the app fall back to NullAiService, so the
    | whole system still runs when Python or Ollama is not started. Text
    | matching keeps working; image similarity is simply skipped.
    |
    */

    'ai' => [
        'enabled' => env('AI_SERVICE_ENABLED', false),
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),

        // Description generation runs a vision model on CPU and takes
        // minutes, so it cannot share the fast timeout above.
        'analyze_timeout' => (int) env('AI_SERVICE_ANALYZE_TIMEOUT', 300),

        // Shared secret sent as a bearer token. The AI service is never
        // exposed to the browser, but this stops any other process on the
        // machine from calling it and pushing junk into the index.
        'token' => env('AI_SERVICE_TOKEN'),
    ],

];
