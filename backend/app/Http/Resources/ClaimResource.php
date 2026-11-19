<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the Claim interface in src/types/index.ts.
 *
 * @mixin \App\Models\Claim
 */
class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStaff = (bool) $request->user()?->canActAsStaff();

        return [
            'id' => (string) $this->id,
            'foundItem' => new FoundItemResource($this->whenLoaded('foundItem')),
            'lostItem' => new LostItemResource($this->whenLoaded('lostItem')),

            // Staff only, and only when the claim has no linked report.
            'claimantLostReports' => LostItemResource::collection($this->whenLoaded('claimantLostReports')),

            'claimant' => $this->whenLoaded('claimant', fn () => [
                'id' => (string) $this->claimant->id,
                'name' => $this->claimant->name,
                // SECURITY: contact details are for staff only, so they
                // can reach the claimant. Never sent to students.
                ...($isStaff ? [
                    'email' => $this->claimant->email,
                    'admissionNumber' => $this->claimant->admission_number,
                ] : []),
            ]),

            // Who handed the item in, with contact details - staff only.
            'finder' => $this->when(
                $isStaff && $this->relationLoaded('foundItem')
                    && $this->foundItem->relationLoaded('user') && $this->foundItem->user !== null,
                fn () => [
                    'id' => (string) $this->foundItem->user->id,
                    'name' => $this->foundItem->user->name,
                    'role' => $this->foundItem->user->role->value,
                    'email' => $this->foundItem->user->email,
                    'admissionNumber' => $this->foundItem->user->admission_number,
                ]
            ),

            'identifyingInformation' => $this->identifying_information,

            'verificationAnswers' => $this->whenLoaded(
                'verifications',
                /**
                 * Only the claimant's question/answer pairs. The
                 * expected_answer and result columns are no longer used:
                 * staff check answers against the found item's private
                 * verification info on the review screen instead.
                 */
                fn () => $this->verifications->map(fn ($verification) => [
                    'question' => $verification->question,
                    'answer' => $verification->answer,
                ])->all()
            ),

            'status' => $this->status->value,

            'reviewedBy' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => (string) $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),

            // Shown to the claimant: they are entitled to know why a
            // decision went the way it did.
            'reviewNotes' => $this->review_notes,

            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
