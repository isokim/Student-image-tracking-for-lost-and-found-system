<?php

namespace App\Providers;

use App\Services\Ai\AiServiceInterface;
use App\Services\Ai\FastApiAiService;
use App\Services\Ai\NullAiService;
use Illuminate\Support\ServiceProvider;

/**
 * Decides which AI implementation the application uses.
 *
 * This is the relocation seam the brief asks for. To move the AI service
 * to a cloud host you change AI_SERVICE_URL in .env; to swap it for a
 * different provider entirely you write a new class implementing
 * AiServiceInterface and bind it below. Nothing else in Laravel changes,
 * and the React app is unaffected, because it only ever talks to Laravel.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiServiceInterface::class, function () {
            /**
             * Falling back to NullAiService rather than failing to boot is
             * deliberate: a student should be able to clone this repo and
             * run the whole system without installing Python or Ollama.
             */
            if (! config('services.ai.enabled')) {
                return new NullAiService;
            }

            return new FastApiAiService(
                baseUrl: config('services.ai.url'),
                timeout: config('services.ai.timeout'),
                token: config('services.ai.token'),
                analyzeTimeout: config('services.ai.analyze_timeout'),
            );
        });
    }
}
