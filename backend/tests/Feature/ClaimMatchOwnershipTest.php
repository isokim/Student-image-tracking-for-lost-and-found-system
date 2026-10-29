<?php

namespace Tests\Feature;

use App\Enums\LostItemStatus;
use App\Enums\MatchStatus;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A claim may reference the AI match that prompted it. That match id
 * arrives from the client, so it has to be treated as untrusted: it must
 * belong to the claimant AND point at the item being claimed.
 *
 * Without both checks a student can attach someone else's match to their
 * own claim, which forges the "this student reported losing exactly this
 * last week" corroboration staff rely on, and - once the claim is
 * completed - closes the victim's genuine lost report.
 */
class ClaimMatchOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $attacker;
    private User $victim;
    private User $finder;
    private User $staff;
    private FoundItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attacker = User::factory()->student()->create();
        $this->victim = User::factory()->student()->create();
        $this->finder = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();

        $this->item = FoundItem::factory()->for_user($this->finder)->verified()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'foundItemId' => $this->item->id,
            'identifyingInformation' => 'This is definitely my property, I can describe it in detail.',
            'verificationAnswers' => [
                ['question' => 'What is on it?', 'answer' => 'A sticker'],
            ],
        ], $overrides);
    }

    public function test_a_claim_cannot_reference_another_students_match(): void
    {
        Notification::fake();

        // The victim's own lost report and its AI suggestion.
        $victimsLost = LostItem::factory()->for_user($this->victim)->create();
        $victimsMatch = ItemMatch::factory()->create([
            'lost_item_id' => $victimsLost->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->attacker)
            ->postJson('/api/claims', $this->payload(['matchId' => $victimsMatch->id]))
            ->assertStatus(404);

        $this->assertDatabaseCount('claims', 0);

        // The victim's match must not have been touched.
        $this->assertSame(MatchStatus::PendingReview, $victimsMatch->fresh()->status);
    }

    public function test_a_hijacked_match_cannot_attach_the_victims_lost_report_to_the_claim(): void
    {
        Notification::fake();

        $victimsLost = LostItem::factory()->for_user($this->victim)->create();
        $victimsMatch = ItemMatch::factory()->create([
            'lost_item_id' => $victimsLost->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->attacker)
            ->postJson('/api/claims', $this->payload(['matchId' => $victimsMatch->id]));

        // Even if a claim somehow existed, it must not carry the victim's
        // report as forged corroboration.
        $this->assertDatabaseMissing('claims', ['lost_item_id' => $victimsLost->id]);
    }

    public function test_completing_a_claim_cannot_resolve_another_students_lost_report(): void
    {
        Notification::fake();

        $victimsLost = LostItem::factory()->for_user($this->victim)->create();
        $victimsMatch = ItemMatch::factory()->create([
            'lost_item_id' => $victimsLost->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->attacker)
            ->postJson('/api/claims', $this->payload(['matchId' => $victimsMatch->id]));

        $claim = Claim::first();

        if ($claim !== null) {
            $this->actingAs($this->staff)->postJson("/api/claims/{$claim->id}/approve");
            $this->actingAs($this->staff)->postJson("/api/claims/{$claim->id}/return");
        }

        // The victim's report must still be open. Closing it would hide a
        // genuinely missing item from all future matching.
        $this->assertSame(LostItemStatus::Active, $victimsLost->fresh()->status);
    }

    public function test_a_claim_cannot_reference_a_match_for_a_different_item(): void
    {
        Notification::fake();

        // The attacker's own match, but pointing at some other item.
        $attackersLost = LostItem::factory()->for_user($this->attacker)->create();
        $otherItem = FoundItem::factory()->verified()->create();
        $mismatched = ItemMatch::factory()->create([
            'lost_item_id' => $attackersLost->id,
            'found_item_id' => $otherItem->id,
        ]);

        $this->actingAs($this->attacker)
            ->postJson('/api/claims', $this->payload(['matchId' => $mismatched->id]))
            ->assertStatus(422);

        $this->assertDatabaseCount('claims', 0);
    }

    public function test_a_student_can_still_claim_using_their_own_valid_match(): void
    {
        Notification::fake();

        $ownLost = LostItem::factory()->for_user($this->attacker)->create();
        $ownMatch = ItemMatch::factory()->create([
            'lost_item_id' => $ownLost->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->attacker)
            ->postJson('/api/claims', $this->payload(['matchId' => $ownMatch->id]))
            ->assertCreated();

        $claim = Claim::firstOrFail();

        // The legitimate path still links the report and flips the match.
        $this->assertSame($ownLost->id, $claim->lost_item_id);
        $this->assertSame(MatchStatus::ClaimStarted, $ownMatch->fresh()->status);
    }
}
