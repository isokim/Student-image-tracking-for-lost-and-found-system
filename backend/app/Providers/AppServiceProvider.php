<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Department;
use App\Models\FoundItem;
use App\Models\ItemImage;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\Message;
use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Policies\ReferenceDataPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * Store short aliases in polymorphic item_type columns instead of
         * fully-qualified class names. Two reasons: the database stays
         * readable ("found_item" not "App\Models\FoundItem"), and moving
         * or renaming a PHP class later does not orphan every existing row.
         */
        Relation::enforceMorphMap([
            // Polymorphic relations: images, AI analysis, embeddings and
            // status history all hang off these two.
            'lost_item' => LostItem::class,
            'found_item' => FoundItem::class,

            /**
             * The rest are here because AuditLogger records entity_type
             * via getMorphClass(), and enforceMorphMap (as opposed to
             * morphMap) throws for any model NOT listed. That strictness
             * is the point: a new auditable model has to be named here
             * deliberately, rather than silently writing a fully-qualified
             * class name into the audit trail that then breaks the moment
             * the class is renamed.
             */
            'user' => User::class,
            'claim' => Claim::class,
            'claim_verification' => ClaimVerification::class,
            'item_match' => ItemMatch::class,
            'item_image' => ItemImage::class,
            'category' => Category::class,
            'department' => Department::class,
            'audit_log' => AuditLog::class,
            'message' => Message::class,
        ]);

        /**
         * Fail loudly in development instead of silently returning null for
         * a relationship nobody remembered to eager-load. Off in production
         * so a missed eager-load degrades to a slow page, not an error.
         */
        /**
         * Return API resources unwrapped.
         *
         * By default JsonResource nests everything under a "data" key, so
         * GET /api/user would return {"data": {...}}. The React client
         * reads the user object directly (authService.me() is typed
         * Promise<User>), so the wrapper has to go.
         *
         * Paginated collections are unaffected - they still return
         * {data: [...], meta: {...}}, which is the shape
         * PaginatedResponse<T> expects.
         */
        JsonResource::withoutWrapping();

        /**
         * Policies in app/Policies are discovered automatically by name
         * (App\Models\Claim -> App\Policies\ClaimPolicy). DatabaseNotification
         * lives in the framework, not App\Models, so its policy has to be
         * registered by hand or the convention finds nothing and every
         * notification check silently falls through to "denied".
         */
        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);

        /**
         * Category does not match the {Model}Policy naming convention
         * (CategoryPolicy), so it has to be registered explicitly - the
         * convention alone would find nothing and every authorisation
         * check would silently fall through to "denied".
         */
        Gate::policy(Category::class, ReferenceDataPolicy::class);

        $this->configureRateLimiting();

        Model::preventLazyLoading(! app()->isProduction());

        /**
         * Note: preventSilentlyDiscardingAttributes is deliberately NOT
         * enabled. Silently dropping a non-fillable attribute is exactly
         * the behaviour we want for mass-assignment protection - someone
         * posting role=admin should have it ignored, not trigger a 500.
         */
    }

    /**
     * Baseline throttling for the whole API.
     *
     * Keyed on the user id when signed in, so one student hammering the
     * API cannot lock out everyone else behind the same campus NAT -
     * which is exactly what a per-IP-only limit would do here.
     *
     * Individual routes still layer tighter limits on top (login,
     * registration, image search); this is the floor, not the ceiling.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(120)->by('user:'.$request->user()->id)
                : Limit::perMinute(30)->by('ip:'.$request->ip());
        });

        /**
         * Photos are the most sensitive thing the API serves and the
         * easiest to scrape, so they get their own tighter bucket. A page
         * of results loads a handful of thumbnails; nobody legitimately
         * needs hundreds a minute.
         */
        RateLimiter::for('images', function (Request $request) {
            return Limit::perMinute(60)->by('images:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
