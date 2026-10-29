<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Matches the Claim interface in src/types/index.ts.
 *
 * @mixin \App\Models\Claim
 */
class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $canSeeExpectedAnswers = $viewer !== null
            && Gate::forUser($viewer)->allows('viewVerificationAnswers', $this->resource);

        return [
            'id' => (string) $this->id,
            'foundItem' => new FoundItemResource($this->whenLoaded('foundItem')),
            'lostItem' => new LostItemResource($this->whenLoaded('lostItem')),

            'claimant' => $this->whenLoaded('claimant', fn () => [
                'id' => (string) $this->claimant->id,
                'name' => $this->claimant->name,
            ]),

            'identifyingInformation' => $this->identifying_information,

            'verificationAnswers' => $this->whenLoaded(
                'verifications',
                fn () => $this->verifications->map(fn ($verification) => array_filter([
                    'question' => $verification->question,
                    'answer' => $verification->answer,

                    /**
                     * SECURITY: expectedAnswer and result are for the
                     * reviewer only.
                     *
                     * If the claimant could read the expected answer, the
                     * verification step would prove nothing - they could
                     * simply repeat it. array_filter drops the keys
                     * entirely rather than sending nulls, so the response
                     * gives no hint that the field exists.
                     */
                    'expectedAnswer' => $canSeeExpectedAnswers ? $verification->expected_answer : null,
                    'result' => $canSeeExpectedAnswers ? $verification->result->value : null,
                ], fn ($value) => $value !== null))->all()
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
