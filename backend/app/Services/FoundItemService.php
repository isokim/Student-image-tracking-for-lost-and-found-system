<?php

namespace App\Services;

use App\Enums\FoundItemStatus;
use App\Events\FoundItemVerified;
use App\Jobs\FindPotentialMatches;
use App\Jobs\ProcessItemImage;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\User;
use App\Notifications\FoundItemVerifiedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class FoundItemService
{
    public function __construct(
        private readonly ItemImageService $images,
        private readonly ItemStatusRecorder $status,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(User $finder, array $data, ?UploadedFile $photo = null): FoundItem
    {
        $item = DB::transaction(function () use ($finder, $data, $photo) {
            $item = new FoundItem([
                'category_id' => $this->resolveCategoryId($data['category']),

                // The place as the finder typed it - the only location
                // there is now that the admin-managed list is gone.
                'location_text' => $data['location'],

                'title' => $data['title'],
                'description' => $data['description'],
                'private_verification_info' => $data['privateVerificationInfo'] ?? null,
                'colour' => $data['colour'] ?? null,
                'additional_info' => $data['additionalInfo'] ?? null,
                'found_at' => $data['foundAt'],
            ]);

            $item->user_id = $finder->id;

            /**
             * Everything starts unverified, including items handed in by
             * staff. Staff verification means "I have this object in my
             * hand", which is a separate act from filing the report - so
             * even a staff member's own report waits for a sign-off.
             */
            $item->status = FoundItemStatus::PendingVerification;
            $item->save();

            $item->statusHistory()->create([
                'from_status' => null,
                'to_status' => FoundItemStatus::PendingVerification->value,
                'changed_by' => $finder->id,
                'note' => 'Item handed in.',
            ]);

            if ($photo) {
                $this->images->store($photo, $item);
            }

            $this->audit->log(
                AuditLogger::ITEM_CREATED,
                $item,
                "Found item reported: {$item->title}",
                $finder,
            );

            return $item;
        });

        /**
         * Queued after commit - see the note in LostItemService::create.
         *
         * Unconditional, photo or not: the analysis step is skipped when
         * there is nothing to analyse, but the pipeline still needs to
         * run so ai_processed_at gets stamped.
         */
        ProcessItemImage::dispatch($item->getMorphClass(), $item->id);

        return $item->fresh();
    }

    public function update(FoundItem $item, array $data, User $actor): FoundItem
    {
        DB::transaction(function () use ($item, $data, $actor) {
            // See the note in LostItemService::update - keyed on
            // presence so a field can actually be cleared.
            $map = [
                'title' => 'title',
                'description' => 'description',
                'privateVerificationInfo' => 'private_verification_info',
                'colour' => 'colour',
                'additionalInfo' => 'additional_info',
                'foundAt' => 'found_at',
                'location' => 'location_text',
            ];

            foreach ($map as $input => $column) {
                if (array_key_exists($input, $data)) {
                    $item->{$column} = $data[$input];
                }
            }

            if (isset($data['category'])) {
                $item->category_id = $this->resolveCategoryId($data['category']);
            }

            $item->save();

            $this->audit->log(AuditLogger::ITEM_UPDATED, $item, "Found item updated: {$item->title}", $actor);
        });

        return $item->fresh();
    }

    /**
     * Staff confirm the item physically exists. Only now does it become
     * visible to students and eligible for matching.
     */
    public function verify(FoundItem $item, User $staff): FoundItem
    {
        DB::transaction(function () use ($item, $staff) {
            $item->verified_by = $staff->id;
            $item->verified_at = now();
            $item->save();

            $this->status->transition(
                $item,
                FoundItemStatus::Verified,
                $staff,
                'Verified by staff.',
            );

            $this->audit->log(
                AuditLogger::ITEM_VERIFIED,
                $item,
                "Found item verified: {$item->title}",
                $staff,
            );
        });

        $item->refresh();

        // Tell the person who handed it in that it has been accepted.
        $item->user->notify(new FoundItemVerifiedNotification($item));

        // Real-time nudge for anyone watching (see routes/channels.php).
        FoundItemVerified::dispatch($item);

        /**
         * Only now is the item eligible for matching.
         *
         * The pipeline that ran at creation time analysed the photo, but
         * MatchFinderService skips items that are not publicly visible -
         * so at that point matching was deliberately a no-op. Verification
         * is the moment to actually look for its owner.
         */
        FindPotentialMatches::dispatch($item->getMorphClass(), $item->id);

        return $item;
    }

    public function reject(FoundItem $item, User $staff, string $reason): FoundItem
    {
        DB::transaction(function () use ($item, $staff, $reason) {
            $item->rejection_reason = $reason;
            $item->verified_by = $staff->id;
            $item->verified_at = now();
            $item->save();

            $this->status->transition($item, FoundItemStatus::Rejected, $staff, $reason);

            $this->audit->log(
                AuditLogger::ITEM_REJECTED,
                $item,
                "Found item rejected: {$item->title} - {$reason}",
                $staff,
            );
        });

        return $item->fresh();
    }

    /** The item was physically handed back to its owner. */
    public function markReturned(FoundItem $item, User $staff): FoundItem
    {
        DB::transaction(function () use ($item, $staff) {
            $this->status->transition($item, FoundItemStatus::Returned, $staff, 'Collected by the owner.');

            $this->audit->log(
                AuditLogger::ITEM_RETURNED,
                $item,
                "Item returned to owner: {$item->title}",
                $staff,
            );
        });

        return $item->fresh();
    }

    public function delete(FoundItem $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor) {
            $this->audit->log(AuditLogger::ITEM_DELETED, $item, "Found item deleted: {$item->title}", $actor);
            $item->delete();
        });
    }

    private function resolveCategoryId(string $slug): int
    {
        return Category::where('slug', $slug)->value('id');
    }
}
