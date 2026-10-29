<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Staff = 'staff';
    case Admin = 'admin';

    /**
     * Roles that may be chosen during public registration.
     *
     * SECURITY: this is why a user cannot POST role=admin to /api/register.
     * The registration controller ignores any submitted role entirely and
     * always assigns Student; this list documents the rule.
     */
    public static function selfAssignable(): array
    {
        return [self::Student];
    }

    /** Staff and admins both work the counter; admins can do everything staff can. */
    public function isStaffOrAdmin(): bool
    {
        return $this === self::Staff || $this === self::Admin;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
