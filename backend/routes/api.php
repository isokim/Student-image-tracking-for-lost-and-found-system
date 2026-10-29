<?php

use App\Http\Controllers\Api\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AdminCategoryController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\FoundItemController;
use App\Http\Controllers\Api\ItemImageController;
use App\Http\Controllers\Api\LostItemController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Staff\StaffDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route here is prefixed with /api.
|
| Authentication is Sanctum in SPA mode - a session cookie, not a bearer
| token - so the React client calls /sanctum/csrf-cookie once before its
| first state-changing request. Sanctum registers that route itself, so
| it does not appear below.
|
| AUTHORISATION HAPPENS IN TWO LAYERS, and both are required:
|
|   1. Route middleware  - "is this person signed in, and are they
|                          roughly the kind of user this section is
|                          for?" Middleware runs before the record is
|                          resolved, so this is all it can answer.
|
|   2. Policies          - "may THIS user touch THIS record?" Called
|                          inside the controllers, because only there
|                          does the record exist.
|
| A route being behind role:staff never excuses a controller from
| calling authorize(). The middleware keeps students out of the admin
| section; the policies keep one student out of another student's data.
|
*/

// ---------------------------------------------------------------------
// Guest routes - rate limited by IP
//
// Deliberately NOT behind the `guest` middleware.
//
// That middleware is built for server-rendered apps: it redirects an
// already-signed-in visitor away from the login page to a dashboard.
// Here it broke two things. The redirect target is "/" on the API host,
// which serves no HTML and is not a CORS path - so the browser blocked
// the response and the React app reported "Can't reach the server" for
// what was actually a 302. And it made switching accounts impossible: a
// student signed in on a shared machine could not sign in as anyone
// else, which is the exact case it needs to allow.
//
// Re-authenticating is not a security boundary - the credentials are
// still checked, the session id is still regenerated, and the rate
// limits below still apply. Being signed in is simply not a reason to
// refuse a login.
// ---------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:6,1')->name('register');

// The 5-attempt lockout per email+IP lives in LoginRequest; this is
// a coarser per-IP ceiling on top of it.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')->name('login');

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:6,1')->name('password.email');

Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1')->name('password.update');

// Opened from an email client, so not behind auth - the signed URL is
// what proves the request is genuine.
Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

// ---------------------------------------------------------------------
// Authenticated routes
//
// auth:sanctum - a valid session is required
// active       - a suspended account is rejected immediately, rather
//                than continuing to work until its session expires
// ---------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // --- account ------------------------------------------------------
    Route::get('/user', [AuthController::class, 'me'])->name('user.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    // --- reference data for the report forms --------------------------
    Route::get('/categories', [ReferenceDataController::class, 'categories'])->name('categories.index');

    // --- lost items ---------------------------------------------------
    // /mine is declared BEFORE /{lostItem}, or "mine" would be captured
    // as an id and 404.
    Route::get('/lost-items/mine', [LostItemController::class, 'mine'])->name('lost-items.mine');
    Route::get('/lost-items', [LostItemController::class, 'index'])->name('lost-items.index');
    Route::post('/lost-items', [LostItemController::class, 'store'])
        ->middleware('throttle:20,1')->name('lost-items.store');
    Route::get('/lost-items/{lostItem}', [LostItemController::class, 'show'])->name('lost-items.show');
    Route::put('/lost-items/{lostItem}', [LostItemController::class, 'update'])->name('lost-items.update');
    Route::post('/lost-items/{lostItem}/find-matches', [LostItemController::class, 'findMatches'])
        ->middleware('throttle:10,1')->name('lost-items.find-matches');
    Route::post('/lost-items/{lostItem}/close', [LostItemController::class, 'close'])->name('lost-items.close');
    Route::delete('/lost-items/{lostItem}', [LostItemController::class, 'destroy'])->name('lost-items.destroy');

    // --- found items --------------------------------------------------
    Route::get('/found-items/mine', [FoundItemController::class, 'mine'])->name('found-items.mine');
    Route::get('/found-items', [FoundItemController::class, 'index'])->name('found-items.index');
    Route::post('/found-items', [FoundItemController::class, 'store'])
        ->middleware('throttle:20,1')->name('found-items.store');
    Route::get('/found-items/{foundItem}', [FoundItemController::class, 'show'])->name('found-items.show');
    Route::put('/found-items/{foundItem}', [FoundItemController::class, 'update'])->name('found-items.update');
    Route::delete('/found-items/{foundItem}', [FoundItemController::class, 'destroy'])->name('found-items.destroy');

    /**
     * Verification is staff work, so the route carries role:staff,admin
     * AND the controller calls authorize('verify'). Two layers: the
     * middleware states the intent plainly at the routing table, the
     * policy enforces the detail (an item cannot be verified twice).
     */
    Route::middleware('role:staff,admin')->group(function () {
        Route::post('/found-items/{foundItem}/verify', [FoundItemController::class, 'verify'])
            ->name('found-items.verify');
        Route::post('/found-items/{foundItem}/reject', [FoundItemController::class, 'reject'])
            ->name('found-items.reject');
    });

    // --- item images --------------------------------------------------
    // Served through a controller so ItemImagePolicy runs before any
    // bytes are sent. Never a public symlinked directory.
    // Tighter bucket than the API default: these are the most sensitive
    // responses the app serves and the easiest to scrape in bulk.
    Route::middleware('throttle:images')->group(function () {
        Route::get('/item-images/{itemImage}', [ItemImageController::class, 'show'])->name('item-images.show');
        Route::get('/item-images/{itemImage}/thumbnail', [ItemImageController::class, 'thumbnail'])
            ->name('item-images.thumbnail');
    });

    // --- matches ------------------------------------------------------
    Route::get('/matches', [MatchController::class, 'index'])->name('matches.index');
    Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/matches/{match}/dismiss', [MatchController::class, 'dismiss'])->name('matches.dismiss');

    // --- claims -------------------------------------------------------
    Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::post('/claims', [ClaimController::class, 'store'])
        ->middleware('throttle:10,1')->name('claims.store');
    Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');

    Route::middleware('role:staff,admin')->group(function () {
        Route::post('/claims/{claim}/approve', [ClaimController::class, 'approve'])->name('claims.approve');
        Route::post('/claims/{claim}/reject', [ClaimController::class, 'reject'])->name('claims.reject');
        Route::post('/claims/{claim}/return', [ClaimController::class, 'markReturned'])->name('claims.return');
    });

    // --- notifications ------------------------------------------------
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');

    // --- search -------------------------------------------------------
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');

    /**
     * Image search is throttled harder than keyword search: each call
     * runs a vision model, which is orders of magnitude more expensive
     * than a LIKE query.
     */
    Route::post('/search/image', [SearchController::class, 'image'])
        ->middleware('throttle:10,1')->name('search.image');

    // -----------------------------------------------------------------
    // Staff area
    // -----------------------------------------------------------------
    Route::prefix('staff')->name('staff.')->middleware('role:staff,admin')->group(function () {
        Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');

        // The same controllers as above; staff see a wider slice because
        // the controllers branch on canActAsStaff().
        Route::get('/found-items', [FoundItemController::class, 'index'])->name('found-items');
        Route::get('/claims', [ClaimController::class, 'index'])->name('claims');
    });

    // -----------------------------------------------------------------
    // Admin area
    //
    // role:admin on the whole group, and the controllers still run their
    // own policy checks. A student reaching any of these gets a flat 403
    // that says nothing about what lives here.
    // -----------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        Route::get('/stats', AdminDashboardController::class)->name('stats');

        // users
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/reinstate', [AdminUserController::class, 'reinstate'])->name('users.reinstate');
        Route::post('/users/{user}/role', [AdminUserController::class, 'changeRole'])->name('users.role');

        // The controlled process for creating staff and admin accounts.
        Route::post('/staff', [AdminUserController::class, 'storeStaff'])->name('staff.store');

        // items and claims - the same controllers, unfiltered
        Route::get('/lost-items', [LostItemController::class, 'index'])->name('lost-items');
        Route::get('/found-items', [FoundItemController::class, 'index'])->name('found-items');
        Route::get('/claims', [ClaimController::class, 'index'])->name('claims');

        // reference data
        Route::get('/categories', [AdminCategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [AdminCategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

        // Read-only. There is deliberately no route to write or delete
        // an audit entry.
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
    });
});
