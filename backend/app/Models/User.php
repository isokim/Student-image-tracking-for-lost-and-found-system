<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * SECURITY - mass assignment protection.
     *
     * `role` and `status` are deliberately NOT fillable. Even if someone
     * posts {"role": "admin"} to /api/register, User::create() will drop
     * it. Changing a role is an explicit, audited admin action that
     * assigns the property directly (see UserManagementService).
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'admission_number',
        'department_id',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            // Laravel hashes this automatically on assignment, so a plaintext
            // password can never reach the database.
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lostItems(): HasMany
    {
        return $this->hasMany(LostItem::class);
    }

    public function foundItems(): HasMany
    {
        return $this->hasMany(FoundItem::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class, 'claimant_id');
    }

    public function reviewedClaims(): HasMany
    {
        return $this->hasMany(Claim::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ---------------------------------------------------------------------
    // Role helpers. Policies use these; controllers should not hand-roll
    // string comparisons against $user->role.
    // ---------------------------------------------------------------------

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Staff-level access: true for staff AND admins. */
    public function canActAsStaff(): bool
    {
        return $this->role->isStaffOrAdmin();
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * Where password reset links point. Sends the user to the React app,
     * not to a Laravel view, because this backend is API-only.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token
            .'&email='.urlencode($this->email);

        $this->notify(new \App\Notifications\ResetPasswordNotification($url));
    }
}
