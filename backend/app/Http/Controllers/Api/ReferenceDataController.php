<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

/**
 * The dropdown data the report forms need.
 *
 * Read-only and available to any signed-in user. Only active rows are
 * returned, so a category an admin has retired stops being offered
 * without existing items that reference it breaking.
 *
 * Location is deliberately absent: the person filing the report types
 * the place themselves, so there is no list to send.
 */
class ReferenceDataController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json(
            CategoryResource::collection(Category::active()->orderBy('name')->get())
        );
    }
}
