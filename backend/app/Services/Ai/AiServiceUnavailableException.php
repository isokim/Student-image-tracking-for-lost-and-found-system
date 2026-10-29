<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI service could not do the work.
 *
 * Thrown only by the methods where an empty result would be misleading -
 * embedding generation and AI search. Where a missing result is merely a
 * loss of quality (image analysis, similarity lookup), the service
 * returns an empty value instead, so the app keeps working without the
 * AI.
 *
 * In a queued job this triggers a retry; in a user request the
 * controller turns it into a 503, which is honest: the request was
 * valid, the service is temporarily unavailable.
 */
class AiServiceUnavailableException extends RuntimeException
{
}
