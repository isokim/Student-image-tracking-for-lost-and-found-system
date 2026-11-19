<?php

namespace App\Services;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Enums\MatchStatus;
use App\Enums\UserRole;
use App\Events\ClaimStatusUpdated;
use App\Events\ClaimSubmitted as ClaimSubmittedEvent;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use App\Notifications\ClaimApprovedNotification;
use App\Notifications\ClaimRejectedNotification;
use App\Notifications\ClaimSubmittedNotification;
use App\Notifications\ItemReturnedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The claim workflow: submit, review, complete.
 *
 * Each transition does four things together - change the status, write
 * the audit record, notify the people affected, and broadcast. They are
 * grouped here so no endpoint can perform three of the four.
 *
 * The database work runs in a transaction; notifications and broadcasts
 * are fired afterwards. Sending mail inside a transaction risks telling
 * a student their claim was approved and then rolling the approval back.
 */
class ClaimService
{
    public function __construct(
        private readonly ItemStatusRecorder $status,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array  $verificationAnswers  [['question' => ..., 'answer' => ...], ...]
     * @param  ItemMatch|null  $match  the AI suggestion this claim was
     *        filed from. Takes the model, not an id, because the caller
     *        must already have authorised it - see
     *        ClaimController::resolveMatch. Accepting a raw id here would
     *        put the ownership check back in the hands of whoever
     *        remembers to do it.
     * @param  LostItem|null  $lostItem  the claimant's own open lost report
     *        picked on the claim form, already checked for ownership by
     *        StoreClaimRequest. Takes precedence over the match's report;
     *        the controller refuses a request where the two disagree.
     */
    public function submit(
        User $claimant,
        FoundItem $item,
        string $identifyingInformation,
        array $verificationAnswers,
        ?ItemMatch $match = null,
        ?LostItem $lostItem = null,
    ): Claim {
        $claim = DB::transaction(function () use ($claimant, $item, $identifyingInformation, $verificationAnswers, $match, $lostItem) {
            
            $item = FoundItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $claim = new Claim([
                'found_item_id' => $item->id,
                'item_match_id' => $match?->id,
                'identifying_information' => $identifyingInformation,
            ]);

           
            $claim->claimant_id = $claimant->id;

       
            $claim->status = ClaimStatus::PendingReview;
            $claim->submitted_at = now();

            
            $claim->lost_item_id = $lostItem?->id ?? $match?->lost_item_id;

            $claim->save();

            foreach ($verificationAnswers as $answer) {
                $claim->verifications()->create([
                    'question' => $answer['question'],
                    'answer' => $answer['answer'],
                    
                ]);
            }

            if ($match !== null) {
                $match->status = MatchStatus::ClaimStarted;
                $match->save();
            }

            $this->audit->log(
                AuditLogger::CLAIM_SUBMITTED,
                $claim,
                "Claim submitted for: {$item->title}",
                $claimant,
            );

            return $claim;
        });

        $claim->load(['foundItem', 'claimant']);

        // Every staff member gets the review queue item.
        Notification::send($this->staffRecipients(), new ClaimSubmittedNotification($claim));
        ClaimSubmittedEvent::dispatch($claim);

        return $claim;
    }





    public function approve(Claim $claim, User $reviewer, ?string $notes = null): Claim
    {
        DB::transaction(function () use ($claim, $reviewer, $notes) {
            $claim->status = ClaimStatus::Approved;
            $claim->reviewed_by = $reviewer->id;
            $claim->reviewed_at = now();
            $claim->review_notes = $notes;
            $claim->save();

        

            if ($claim->foundItem !== null) {
                // The item is now spoken for, so it stops being claimable.
                $this->status->transition(
                    $claim->foundItem,
                    FoundItemStatus::Claimed,
                    $reviewer,
                    "Claim #{$claim->id} approved.",
                );
            }

            $this->audit->log(
                AuditLogger::CLAIM_APPROVED,
                $claim,
                "Claim approved for: {$claim->foundItem?->title}",
                $reviewer,
            );
        });

        $claim->refresh()->load(['foundItem', 'claimant']);

        $claim->claimant->notify(new ClaimApprovedNotification($claim));
        ClaimStatusUpdated::dispatch($claim);

        return $claim;
    }

    public function reject(Claim $claim, User $reviewer, string $notes): Claim
    {
        DB::transaction(function () use ($claim, $reviewer, $notes) {
        
            $wasHoldingTheItem = $claim->status === ClaimStatus::Approved;

            $claim->status = ClaimStatus::Rejected;
            $claim->reviewed_by = $reviewer->id;
            $claim->reviewed_at = now();
            $claim->review_notes = $notes;
            $claim->save();

           
            if ($wasHoldingTheItem && $claim->foundItem?->status === FoundItemStatus::Claimed) {
                $this->status->transition(
                    $claim->foundItem,
                    FoundItemStatus::Verified,
                    $reviewer,
                    "Claim #{$claim->id} rejected; item available again.",
                );
            }


            $this->audit->log(
                AuditLogger::CLAIM_REJECTED,
                $claim,
                "Claim rejected for: {$claim->foundItem?->title} - {$notes}",
                $reviewer,
            );
        });

        $claim->refresh()->load(['foundItem', 'claimant']);

        $claim->claimant->notify(new ClaimRejectedNotification($claim));
        ClaimStatusUpdated::dispatch($claim);

        return $claim;
    }




    /** The student collected the item in person. */
    public function complete(Claim $claim, User $staff): Claim
    {
        DB::transaction(function () use ($claim, $staff) {
            $claim->status = ClaimStatus::Completed;
            $claim->completed_at = now();
            $claim->save();

            // Null-guarded for the same reason as approve().
            if ($claim->foundItem !== null) {
                $this->status->transition(
                    $claim->foundItem,
                    FoundItemStatus::Returned,
                    $staff,
                    "Collected by the claimant (claim #{$claim->id}).",
                );
            }

            // Close the matching lost report too, so it stops appearing
            // in the student's open reports and in future matching.
            if ($claim->lostItem !== null) {
                $this->status->transition(
                    $claim->lostItem,
                    \App\Enums\LostItemStatus::Resolved,
                    $staff,
                    'Item recovered.',
                );
            }

            $this->audit->log(
                AuditLogger::CLAIM_COMPLETED,
                $claim,
                "Item returned to claimant: {$claim->foundItem?->title}",
                $staff,
            );
        });

        $claim->refresh()->load(['foundItem', 'claimant']);

        $claim->claimant->notify(new ItemReturnedNotification($claim));
        ClaimStatusUpdated::dispatch($claim);

        return $claim;
    }

    /**
     * Staff who should see the review queue.
     *
     * Suspended accounts are excluded - notifying someone whose access
     * has been revoked is pointless and leaks activity to them.
     */
    private function staffRecipients()
    {
        return User::query()
            ->whereIn('role', [UserRole::Staff, UserRole::Admin])
            ->where('status', \App\Enums\UserStatus::Active)
            ->get();
    }
}
