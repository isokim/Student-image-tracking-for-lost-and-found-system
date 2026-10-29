<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Category management.
 *
 * Every route here is behind `role:admin`, which is the authorisation
 * for this controller: categories are global reference data, so there
 * is no per-record ownership question for a policy to answer.
 */
class AdminCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        // Includes inactive ones, unlike the public reference endpoint -
        // an admin needs to see what they have retired.
        return response()->json(
            CategoryResource::collection(Category::orderBy('name')->get())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:categories,slug'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $category = Category::create($validated + ['is_active' => true]);

        $this->audit->log(
            AuditLogger::CATEGORY_CHANGED,
            $category,
            "Category created: {$category->slug}",
            $request->user(),
        );

        return (new CategoryResource($category))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $this->authorize('update', $category);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'isActive' => ['sometimes', 'boolean'],
            /**
             * The slug is deliberately not editable. Existing items
             * reference this category, and the React app has the slug in
             * a hard-coded union type - renaming one would break the
             * dropdown and orphan reports at the same time.
             */
        ]);

        if (isset($validated['name'])) {
            $category->name = $validated['name'];
        }

        if (array_key_exists('isActive', $validated)) {
            $category->is_active = $validated['isActive'];
        }

        $category->save();

        $this->audit->log(
            AuditLogger::CATEGORY_CHANGED,
            $category,
            "Category updated: {$category->slug}",
            $request->user(),
        );

        return new CategoryResource($category);
    }

    /**
     * Deactivates rather than deletes.
     *
     * Lost and found items reference this row, and the foreign key is
     * restrictOnDelete precisely so a delete cannot orphan them.
     * Deactivating takes it off the report form while leaving history
     * readable.
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->is_active = false;
        $category->save();

        $this->audit->log(
            AuditLogger::CATEGORY_CHANGED,
            $category,
            "Category deactivated: {$category->slug}",
            $request->user(),
        );

        return $this->message('Category deactivated.');
    }
}
