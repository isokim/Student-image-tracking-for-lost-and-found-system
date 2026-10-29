<?php

namespace Tests\Feature;

use App\Enums\ClaimStatus;
use App\Enums\FoundItemStatus;
use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\User;
use App\Notifications\ClaimApprovedNotification;
use App\Notifications\ClaimRejectedNotification;
use App\Notifications\ClaimSubmittedNotification;
use App\Notifications\ItemReturnedNotification;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $claimant;
    private User $otherStudent;
    private User $finder;
    private User $staff;
    private User $admin;
    private FoundItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimant = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->finder = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
        $this->admin = User::factory()->admin()->create();

        $this->item = FoundItem::factory()->for_user($this->finder)->verified()->create([
            'private_verification_info' => 'Lock screen shows a brown dog.',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'foundItemId' => $this->item->id,
            'identifyingInformation' => 'It is my phone - the lock screen is a photo of my dog Bruno.',
            'verificationAnswers' => [
                ['question' => 'What is on the lock screen?', 'answer' => 'A photo of my dog'],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Submitting
    // -----------------------------------------------------------------

    public function test_a_student_can_submit_a_claim(): void
    {
        Notification::fake();

        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('claimant.name', $this->claimant->name);

        $this->assertDatabaseHas('claims', [
            'claimant_id' => $this->claimant->id,
            'status' => 'pending_review',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::CLAIM_SUBMITTED]);

        // Staff get the review queue item; the claimant does not get a
        // notification about their own action.
        Notification::assertSentTo($this->staff, ClaimSubmittedNotification::class);
    }

    /**
     * SECURITY: a claimant must not be able to approve their own claim.
     */
    public function test_a_claimant_cannot_post_an_approved_status(): void
    {
        Notification::fake();

        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload(['status' => 'approved']))
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review');

        $this->assertSame(ClaimStatus::PendingReview, Claim::first()->status);
    }

    public function test_a_claimant_cannot_supply_their_own_expected_answer(): void
    {
        Notification::fake();

        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload([
                'verificationAnswers' => [[
                    'question' => 'What is on the lock screen?',
                    'answer' => 'A photo of my dog',
                    // Supplying the "correct" answer would let the
                    // claimant mark their own homework.
                    'expectedAnswer' => 'A photo of my dog',
                    'result' => 'pass',
                ]],
            ]))
            ->assertCreated();

        $verification = \App\Models\ClaimVerification::first();

        $this->assertNull($verification->expected_answer);
        $this->assertSame('pending', $verification->result->value);
    }

    public function test_a_claim_cannot_be_filed_against_an_unverified_item(): void
    {
        $unverified = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload(['foundItemId' => $unverified->id]))
            ->assertStatus(403);
    }

    public function test_the_finder_cannot_claim_the_item_they_handed_in(): void
    {
        $this->actingAs($this->finder)
            ->postJson('/api/claims', $this->payload())
            ->assertStatus(403);
    }

    public function test_a_student_cannot_open_two_claims_on_the_same_item(): void
    {
        Notification::fake();

        $this->actingAs($this->claimant)->postJson('/api/claims', $this->payload())->assertCreated();
        $this->actingAs($this->claimant)->postJson('/api/claims', $this->payload())->assertStatus(403);

        $this->assertSame(1, Claim::count());
    }

    public function test_a_claim_requires_meaningful_identifying_information(): void
    {
        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload(['identifyingInformation' => 'mine']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifyingInformation');
    }

    public function test_a_claim_requires_verification_answers(): void
    {
        $this->actingAs($this->claimant)
            ->postJson('/api/claims', $this->payload(['verificationAnswers' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('verificationAnswers');
    }

    public function test_a_guest_cannot_submit_a_claim(): void
    {
        $this->postJson('/api/claims', $this->payload())->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Reading - claims are entirely private
    // -----------------------------------------------------------------

    public function test_a_student_cannot_read_another_students_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->claimant->id]);

        // 404, not 403: a 403 would confirm the claim exists.
        $this->actingAs($this->otherStudent)
            ->getJson("/api/claims/{$claim->id}")
            ->assertStatus(404);
    }

    public function test_the_claim_list_shows_a_student_only_their_own_claims(): void
    {
        Claim::factory()->count(2)->create(['claimant_id' => $this->claimant->id]);
        Claim::factory()->count(3)->create(['claimant_id' => $this->otherStudent->id]);

        $response = $this->actingAs($this->claimant)->getJson('/api/claims')->assertOk();

        $this->assertCount(2, $response->json());
    }

    public function test_staff_see_every_claim_in_the_review_queue(): void
    {
        Claim::factory()->count(2)->create(['claimant_id' => $this->claimant->id]);
        Claim::factory()->count(3)->create(['claimant_id' => $this->otherStudent->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/claims')->assertOk();

        $this->assertCount(5, $response->json());
    }

    /**
     * SECURITY: the claimant must never learn the expected answer.
     */
    public function test_the_claimant_cannot_see_the_expected_verification_answers(): void
    {
        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);
        $claim->verifications()->create([
            'question' => 'What is on the lock screen?',
            'answer' => 'A photo of my dog',
            'expected_answer' => 'Lock screen shows a brown dog.',
        ]);

        $response = $this->actingAs($this->claimant)
            ->getJson("/api/claims/{$claim->id}")
            ->assertOk();

        $this->assertStringNotContainsString('brown dog', $response->getContent());
        $this->assertArrayNotHasKey('expectedAnswer', $response->json('verificationAnswers.0'));
    }

    public function test_reviewing_staff_can_see_the_expected_answers(): void
    {
        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);
        $claim->verifications()->create([
            'question' => 'What is on the lock screen?',
            'answer' => 'A photo of my dog',
            'expected_answer' => 'Lock screen shows a brown dog.',
        ]);

        $this->actingAs($this->staff)
            ->getJson("/api/claims/{$claim->id}")
            ->assertOk()
            ->assertJsonPath('verificationAnswers.0.expectedAnswer', 'Lock screen shows a brown dog.');
    }

    // -----------------------------------------------------------------
    // Reviewing
    // -----------------------------------------------------------------

    public function test_staff_can_approve_a_claim(): void
    {
        Notification::fake();

        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/approve", ['notes' => 'ID checked, description matches.'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewedBy.name', $this->staff->name);

        // Approving takes the item off the board.
        $this->assertSame(FoundItemStatus::Claimed, $this->item->fresh()->status);

        Notification::assertSentTo($this->claimant, ClaimApprovedNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::CLAIM_APPROVED]);
    }

    public function test_a_student_cannot_approve_a_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->claimant->id]);

        $this->actingAs($this->otherStudent)
            ->postJson("/api/claims/{$claim->id}/approve")
            ->assertStatus(403);

        $this->assertSame(ClaimStatus::PendingReview, $claim->fresh()->status);
    }

    /**
     * SECURITY: the most valuable attack here - approving your own claim.
     */
    public function test_a_claimant_cannot_approve_their_own_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->claimant->id]);

        $this->actingAs($this->claimant)
            ->postJson("/api/claims/{$claim->id}/approve")
            ->assertStatus(403);
    }

    public function test_a_staff_member_cannot_approve_their_own_claim(): void
    {
        Notification::fake();

        $claim = Claim::factory()->create([
            'claimant_id' => $this->staff->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/approve")
            ->assertStatus(403);
    }

    public function test_staff_can_reject_a_claim_with_a_reason(): void
    {
        Notification::fake();

        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/reject", ['notes' => 'Description did not match the item.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        Notification::assertSentTo($this->claimant, ClaimRejectedNotification::class);
    }

    public function test_rejecting_a_claim_requires_a_reason(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->claimant->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    public function test_rejecting_a_claim_leaves_the_item_available_for_its_real_owner(): void
    {
        Notification::fake();

        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/reject", ['notes' => 'Description did not match the item.'])
            ->assertOk();

        // Still on the board, so whoever actually owns it can claim it.
        $this->assertSame(FoundItemStatus::Verified, $this->item->fresh()->status);
    }

    /**
     * The counterpart, and the reason releasing on item status alone was
     * wrong: when one claim has been approved and a competing claim is
     * rejected, the item must stay with the approved claimant.
     *
     * The full scenario is covered in CompetingClaimsTest.
     */
    public function test_rejecting_a_losing_claim_does_not_take_the_item_back(): void
    {
        Notification::fake();

        $winner = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);
        $loser = Claim::factory()->create([
            'claimant_id' => $this->otherStudent->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)->postJson("/api/claims/{$winner->id}/approve")->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$loser->id}/reject", ['notes' => 'Another claim was approved first.'])
            ->assertOk();

        $this->assertSame(FoundItemStatus::Claimed, $this->item->fresh()->status);
    }

    public function test_a_claim_cannot_be_reviewed_twice(): void
    {
        Notification::fake();

        $claim = Claim::factory()->approved($this->staff)->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/approve")
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Completing
    // -----------------------------------------------------------------

    public function test_staff_can_mark_an_approved_claim_as_returned(): void
    {
        Notification::fake();

        $claim = Claim::factory()->approved($this->staff)->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/return")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame(FoundItemStatus::Returned, $this->item->fresh()->status);

        Notification::assertSentTo($this->claimant, ItemReturnedNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::CLAIM_COMPLETED]);
    }

    public function test_an_unapproved_claim_cannot_be_marked_returned(): void
    {
        $claim = Claim::factory()->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/claims/{$claim->id}/return")
            ->assertStatus(403);
    }

    public function test_a_student_cannot_mark_a_claim_returned(): void
    {
        $claim = Claim::factory()->approved($this->staff)->create([
            'claimant_id' => $this->claimant->id,
            'found_item_id' => $this->item->id,
        ]);

        $this->actingAs($this->claimant)
            ->postJson("/api/claims/{$claim->id}/return")
            ->assertStatus(403);
    }
}
