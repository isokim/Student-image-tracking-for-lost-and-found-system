<?php

namespace App\Policies;

use App\Models\FoundItem;
use App\Models\ItemImage;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Images are served by a controller, not from a public directory, so
 * that this policy runs before any bytes are sent.
 *
 * The rule is simply: you may see the photo if you may see the item it
 * belongs to. Delegating rather than re-implementing the check means an
 * image can never become more visible than its parent - if we tighten
 * FoundItemPolicy later, photos tighten with it automatically.
 *
 * This is why item_images.path is hidden on the model and files live on
 * a private disk. If uploads were written to storage/app/public and
 * symlinked, anyone with the URL could read a photo of a handed-in
 * passport without ever authenticating.
 */
class ItemImagePolicy
{
    public function view(User $user, ItemImage $image): Response
    {
        $item = $image->item;

        if ($item === null) {
            // Orphaned row - the item was deleted. Nothing to authorise.
            return Response::denyAsNotFound();
        }

        $allowed = match (true) {
            $item instanceof LostItem => Gate::forUser($user)->allows('view', $item),
            $item instanceof FoundItem => Gate::forUser($user)->allows('view', $item),
            default => false,
        };

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, ItemImage $image): Response
    {
        $item = $image->item;

        if ($item === null) {
            return Response::denyAsNotFound();
        }

        $allowed = match (true) {
            $item instanceof LostItem => Gate::forUser($user)->allows('update', $item),
            $item instanceof FoundItem => Gate::forUser($user)->allows('update', $item),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny('You cannot remove this photo.');
    }
}
