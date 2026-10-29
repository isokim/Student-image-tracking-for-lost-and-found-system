<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Gives every controller $this->authorize().
     *
     * Laravel 12's slim skeleton leaves this out, and without it
     * $this->authorize() is simply an undefined method - which fails
     * loudly, but only when that line is reached. Adding it here means
     * authorisation is always available rather than something each
     * controller has to remember to import.
     */
    use AuthorizesRequests;

    /**
     * Render a paginated list in the exact shape the React app expects.
     *
     * Laravel's own paginated resource response uses snake_case meta
     * (current_page, last_page, per_page) and adds a `links` block. The
     * frontend's PaginatedResponse<T> type declares camelCase and only
     * four meta keys, so translating here keeps every list endpoint
     * consistent instead of each controller improvising.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * A plain message response, for endpoints with nothing to return
     * (logout, mark-all-read). Always an object, never a bare string, so
     * the client can read `message` uniformly.
     */
    protected function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * Reads a ?status= filter, but only if it is a real member of the
     * given status enum.
     *
     * Returns null for anything else, which `when()` treats as "no
     * filter" and falls back to the default scope. Two reasons this is
     * not just tidiness:
     *
     *  - `$request->string()` hands back a Stringable OBJECT, and passing
     *    that into a query binding relies on implicit conversion.
     *  - an unchecked value let a caller filter to statuses the default
     *    scope deliberately hides.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    protected function validStatus(Request $request, string $enum): ?string
    {
        if (! $request->filled('status')) {
            return null;
        }

        return $enum::tryFrom((string) $request->input('status'))?->value;
    }
}
