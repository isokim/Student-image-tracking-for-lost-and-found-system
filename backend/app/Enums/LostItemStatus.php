<?php

namespace App\Enums;

/**
 * active   - visible to the owner, being matched against found items
 * matched  - at least one possible match exists
 * resolved - the owner got the item back
 * closed   - the owner gave up or withdrew the report
 */
enum LostItemStatus: string
{
    case Active = 'active';
    case Matched = 'matched';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** A closed or resolved report is finished; nothing more happens to it. */
    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::Matched;
    }
}
