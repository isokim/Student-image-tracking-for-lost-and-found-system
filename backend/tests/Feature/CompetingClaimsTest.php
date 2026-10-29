<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Two students can legitimately claim the same item - staff decide
 * between them. What must not happen is both being told to collect it.
 */
class CompetingClaimsTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;
    private User $studentB;
    private User $staff;
    private FoundItem $item;
    private Claim $claimA;
    private Claim $claimB;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->studentA = User::factory()->student()->create();
        $this->studentB = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();

        $this->item = FoundItem::factory()->verified()->create();

        $this->claimA = Claim::factory()->create([
            'claimant_id' => $this->studentA->id,
            'found_item_id' => $this->item->id,
        ]);
        $this->claimB = Claim::factory()->create([
            'claimant_id' => $this->studentB->id,
            'found_item_id' => $this->item->id,
        ]);
    }

    public function test_a_second_claim_cannot_be_approved_once_the_item_is_spoken_for(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimA->id}/approve")
            ->assertOk();

        $this->assertSame(FoundItemStatus::Claimed, $this->item->fresh()->status);

        // Approving B too would tell two students to collect one object.
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimB->id}/approve")
            ->assertStatus(403);

        $this->assertSame(ClaimStatus::PendingReview, $this->claimB->fresh()->status);
    }

    public function test_an_admin_cannot_bypass_the_double_approval_guard_either(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($this->staff)->postJson("/api/claims/{$this->claimA->id}/approve")->assertOk();

        // This is data integrity, not permissions - nobody overrides it.
        $this->actingAs($admin)
            ->postJson("/api/claims/{$this->claimB->id}/approve")
            ->assertStatus(403);
    }

    public function test_rejecting_the_losing_claim_does_not_release_the_item(): void
    {
        $this->actingAs($this->staff)->postJson("/api/claims/{$this->claimA->id}/approve")->assertOk();

        // Staff clear the queue by rejecting B. The item belongs to A,
        // who has already been told to come and collect it.
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimB->id}/reject", ['notes' => 'Another claim was approved first.'])
            ->assertOk();

        $this->assertSame(
            FoundItemStatus::Claimed,
            $this->item->fresh()->status,
            'rejecting a losing claim must not put the item back on the board'
        );

        $this->assertSame(ClaimStatus::Approved, $this->claimA->fresh()->status);
    }

    public function test_a_third_student_cannot_claim_the_item_after_the_losing_claim_is_rejected(): void
    {
        $thirdStudent = User::factory()->student()->create();

        $this->actingAs($this->staff)->postJson("/api/claims/{$this->claimA->id}/approve")->assertOk();
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimB->id}/reject", ['notes' => 'Another claim was approved first.'])
            ->assertOk();

        $this->actingAs($thirdStudent)
            ->postJson('/api/claims', [
                'foundItemId' => $this->item->id,
                'identifyingInformation' => 'I am quite sure this one belongs to me actually.',
                'verificationAnswers' => [['question' => 'Colour?', 'answer' => 'Black']],
            ])
            ->assertStatus(403);
    }

    public function test_rejecting_the_only_claim_does_release_the_item(): void
    {
        // The normal case must still work: a single claim rejected before
        // approval leaves the item available for its real owner.
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimA->id}/reject", ['notes' => 'Description did not match.'])
            ->assertOk();

        $this->assertSame(FoundItemStatus::Verified, $this->item->fresh()->status);

        // And B can still be approved.
        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$this->claimB->id}/approve")
            ->assertOk();
    }
}
