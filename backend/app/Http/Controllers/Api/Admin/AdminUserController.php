<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * User administration.
 *
 * This controller is the "controlled process" the brief asks for:
 * staff and admin accounts are created here, by an existing admin, and
 * never through public registration.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** GET /api/admin/users?role=student */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('department')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.addcslashes($request->string('search'), '%_\\').'%';
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('admission_number', 'like', $term));
            })
            ->latest()
            ->paginate(20);

        return $this->paginated($users, UserResource::class);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('department'));
    }

    /**
     * POST /api/admin/staff - provision a staff or admin account.
     *
     * The role is taken from validated input here, unlike registration,
     * because an admin choosing it IS the controlled process. The policy
     * check above is what makes that safe.
     */
    public function storeStaff(StoreStaffUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = DB::transaction(function () use ($request) {
            $user = new User([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'department_id' => $request->validated('departmentId'),
            ]);

            // Assigned as a property because role is not fillable - the
            // same protection that stops public registration setting it.
            $user->role = UserRole::from($request->validated('role'));
            $user->status = UserStatus::Active;

            // Provisioned by an admin in person, so no email round-trip.
            $user->email_verified_at = now();
            $user->save();

            $this->audit->log(
                AuditLogger::STAFF_CREATED,
                $user,
                "Created {$user->role->value} account for {$user->email}",
            );

            return $user;
        });

        return (new UserResource($user->load('department')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/admin/users/{user}/suspend
     *
     * The policy refuses self-suspension and refuses to suspend another
     * admin, so an administrator cannot lock themselves or their
     * colleagues out of the system from here.
     */
    public function suspend(Request $request, User $user): UserResource
    {
        $this->authorize('suspend', $user);

        $user->status = UserStatus::Suspended;
        $user->suspended_at = now();
        $user->save();

        /**
         * Kill their sessions as well as flagging the account.
         *
         * The `active` middleware already rejects each request, but
         * deleting the session rows evicts them immediately rather than
         * relying on that check firing - and revokes any API tokens too.
         */
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        $this->audit->log(
            AuditLogger::USER_SUSPENDED,
            $user,
            "Suspended account: {$user->email}",
            $request->user(),
        );

        return new UserResource($user->load('department'));
    }

    public function reinstate(Request $request, User $user): UserResource
    {
        $this->authorize('reinstate', $user);

        $user->status = UserStatus::Active;
        $user->suspended_at = null;
        $user->save();

        $this->audit->log(
            AuditLogger::USER_REINSTATED,
            $user,
            "Reinstated account: {$user->email}",
            $request->user(),
        );

        return new UserResource($user->load('department'));
    }

    /** POST /api/admin/users/{user}/role */
    public function changeRole(Request $request, User $user): UserResource
    {
        $this->authorize('changeRole', $user);

        $validated = $request->validate([
            'role' => ['required', \Illuminate\Validation\Rule::enum(UserRole::class)],
        ]);

        $previous = $user->role->value;
        $user->role = UserRole::from($validated['role']);
        $user->save();

        $this->audit->log(
            AuditLogger::USER_ROLE_CHANGED,
            $user,
            "Role changed from {$previous} to {$user->role->value} for {$user->email}",
            $request->user(),
        );

        return new UserResource($user->load('department'));
    }
}
