<?php

namespace App\Services;

use App\Enums\LostItemStatus;
use App\Jobs\ProcessItemImage;
use App\Models\Category;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for lost reports.
 *
 * Controllers call this; they do not touch the model directly. That
 * keeps ownership assignment, the audit entry, the status history and
 * the AI dispatch together in one place, so none of them can be
 * forgotten by a new endpoint later.
 */
class LostItemService
{
    public function __construct(
        private readonly ItemImageService $images,
        private readonly ItemStatusRecorder $status,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array  $data  already validated by StoreLostItemRequest
     */
    public function create(User $owner, array $data, ?UploadedFile $photo = null): LostItem
    {
        $item = DB::transaction(function () use ($owner, $data, $photo) {
            $item = new LostItem([
                'category_id' => $this->resolveCategoryId($data['category']),

                // The place as the student typed it - the only location
                // there is now that the admin-managed list is gone.
                'location_text' => $data['location'],

                'title' => $data['title'],
                'description' => $data['description'],
                'colour' => $data['colour'] ?? null,
                'additional_info' => $data['additionalInfo'] ?? null,
                'lost_at' => $data['lostAt'],
            ]);

            /**
             * Ownership is taken from the authenticated user, never from
             * the request. A payload containing "userId": 7 cannot file a
             * report in someone else's name.
             */
            $item->user_id = $owner->id;
            $item->status = LostItemStatus::Active;
            $item->save();

            $item->statusHistory()->create([
                'from_status' => null,
                'to_status' => LostItemStatus::Active->value,
                'changed_by' => $owner->id,
                'note' => 'Report created.',
            ]);

            if ($photo) {
                $this->images->store($photo, $item);
            }

            $this->audit->log(
                AuditLogger::ITEM_CREATED,
                $item,
                "Lost report created: {$item->title}",
                $owner,
            );

            return $item;
        });

        /**
         * Dispatched AFTER the transaction commits, not inside it.
         *
         * A queue worker is a separate process. If the job were queued
         * inside the transaction, the worker could pick it up and look
         * for a row that has not been committed yet - an intermittent
         * "item not found" that is painful to reproduce.
         *
         * It is queued, not run inline, because vision inference takes
         * seconds and the student should not wait for it to see their
         * report saved.
         *
         * And it is dispatched unconditionally, photo or not. This used
         * to be guarded by `if ($item->images()->exists())`, which meant
         * a report filed without a photo was never matched against
         * anything, since ProcessItemImage is the only thing that
         * dispatches FindPotentialMatches for a lost item. Plenty of
         * students file without a picture, and text, category, location
         * and time matching all work fine without one. ProcessItemImage
         * handles the no-photo case by skipping straight to matching.
         */
        ProcessItemImage::dispatch($item->getMorphClass(), $item->id);

        return $item->fresh();
    }

    public function update(LostItem $item, array $data, User $actor): LostItem
    {
        DB::transaction(function () use ($item, $data, $actor) {
            /**
             * Keyed on what the request actually sent, not on what is
             * non-null.
             *
             * Filtering out nulls meant an optional field could never be
             * cleared: PUT {"colour": null} validated fine, arrived as
             * null, was filtered away, and returned 200 with the old
             * value still in place. Mapping camelCase request keys to
             * column names first lets an explicit null through while an
             * absent key is still left untouched.
             */
            $map = [
                'title' => 'title',
                'description' => 'description',
                'colour' => 'colour',
                'additionalInfo' => 'additional_info',
                'lostAt' => 'lost_at',
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

            $this->audit->log(AuditLogger::ITEM_UPDATED, $item, "Lost report updated: {$item->title}", $actor);
        });

        return $item->fresh();
    }

    /** The owner giving up on a report, or confirming they got it back. */
    public function close(LostItem $item, User $actor, bool $resolved = false): LostItem
    {
        $target = $resolved ? LostItemStatus::Resolved : LostItemStatus::Closed;

        DB::transaction(function () use ($item, $actor, $target) {
            $this->status->transition($item, $target, $actor, 'Closed by the owner.');

            $this->audit->log(
                AuditLogger::ITEM_CLOSED,
                $item,
                "Lost report {$target->value}: {$item->title}",
                $actor,
            );
        });

        return $item->fresh();
    }

    public function delete(LostItem $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor) {
            $this->audit->log(AuditLogger::ITEM_DELETED, $item, "Lost report deleted: {$item->title}", $actor);

            // Soft delete: the report may be referenced by a match or a
            // claim, and hard-deleting it would tear holes in the audit
            // trail. The images stay on disk for the same reason.
            $item->delete();
        });
    }

    /**
     * The API speaks in category slugs ("electronics"); the database uses
     * ids. Validation has already confirmed the slug exists.
     */
    private function resolveCategoryId(string $slug): int
    {
        return Category::where('slug', $slug)->value('id');
    }
}
