<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Exercises the policies directly, without going through HTTP.
 *
 * Route-level tests come later and cover the same ground end to end;
 * these exist so that a broken rule points at the exact policy method
 * rather than at a controller.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherStudent;
    private User $staff;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
        $this->admin = User::factory()->admin()->create();
    }

    // -----------------------------------------------------------------
    // Lost items: browsable, but private detail is not
    // -----------------------------------------------------------------

    public function test_any_student_can_view_a_lost_report_because_the_board_is_browsable(): void
    {
        $item = LostItem::factory()->for_user($this->owner)->create();

        $this->assertTrue(Gate::forUser($this->otherStudent)->allows('view', $item));
    }

    public function test_only_the_owner_and_staff_see_a_lost_reports_private_details(): void
    {
        $item = LostItem::factory()->for_user($this->owner)->create();

        $this->assertTrue(Gate::forUser($this->owner)->allows('viewPrivateDetails', $item));
        $this->assertTrue(Gate::forUser($this->staff)->allows('viewPrivateDetails', $item));
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewPrivateDetails', $item));

        // The rule the brief calls out.
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('viewPrivateDetails', $item));
    }

    public function test_a_student_cannot_edit_another_students_lost_report(): void
    {
        $item = LostItem::factory()->for_user($this->owner)->create();

        $this->assertTrue(Gate::forUser($this->owner)->allows('update', $item));
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('update', $item));
    }

    public function test_a_closed_lost_report_cannot_be_edited_even_by_its_owner(): void
    {
        $item = LostItem::factory()->for_user($this->owner)->closed()->create();

        $this->assertFalse(Gate::forUser($this->owner)->allows('update', $item));
    }

    // -----------------------------------------------------------------
    // Found items: hidden until verified
    // -----------------------------------------------------------------

    public function test_an_unverified_found_item_is_hidden_from_other_students(): void
    {
        $item = FoundItem::factory()->for_user($this->owner)->create(); // pending_verification

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $item), 'the finder can see it');
        $this->assertTrue(Gate::forUser($this->staff)->allows('view', $item), 'staff can see it');
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('view', $item));
    }

    public function test_hiding_an_unverified_item_returns_404_not_403_so_it_cannot_be_enumerated(): void
    {
        $item = FoundItem::factory()->for_user($this->owner)->create();

        $response = Gate::forUser($this->otherStudent)->inspect('view', $item);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_a_verified_found_item_is_visible_to_everyone(): void
    {
        $item = FoundItem::factory()->verified()->create();

        $this->assertTrue(Gate::forUser($this->otherStudent)->allows('view', $item));
    }

    public function test_the_private_verification_detail_is_never_visible_to_a_browsing_student(): void
    {
        $item = FoundItem::factory()->for_user($this->owner)->verified()->create();

        $this->assertTrue(Gate::forUser($this->owner)->allows('viewPrivateDetails', $item));
        $this->assertTrue(Gate::forUser($this->staff)->allows('viewPrivateDetails', $item));

        // Even though the item itself is publicly viewable.
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('viewPrivateDetails', $item));
    }

    public function test_a_student_cannot_verify_a_found_item(): void
    {
        $item = FoundItem::factory()->for_user($this->owner)->create();

        $this->assertFalse(Gate::forUser($this->owner)->allows('verify', $item), 'not even their own');
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('verify', $item));
        $this->assertTrue(Gate::forUser($this->staff)->allows('verify', $item));
    }

    public function test_an_item_cannot_be_verified_twice(): void
    {
        $item = FoundItem::factory()->verified()->create();

        $this->assertFalse(Gate::forUser($this->staff)->allows('verify', $item));
    }

    // -----------------------------------------------------------------
    // Claims: entirely private
    // -----------------------------------------------------------------

    public function test_a_student_cannot_view_another_students_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->owner->id]);

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $claim));
        $this->assertTrue(Gate::forUser($this->staff)->allows('view', $claim));
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('view', $claim));

        // And the denial hides the record's existence.
        $this->assertSame(404, Gate::forUser($this->otherStudent)->inspect('view', $claim)->status());
    }

    public function test_a_claimant_cannot_see_the_expected_verification_answers(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->owner->id]);

        // Knowing the expected answer would defeat the entire check.
        $this->assertFalse(Gate::forUser($this->owner)->allows('viewVerificationAnswers', $claim));
        $this->assertTrue(Gate::forUser($this->staff)->allows('viewVerificationAnswers', $claim));
    }

    public function test_a_student_cannot_review_a_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->owner->id]);

        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('review', $claim));
        $this->assertTrue(Gate::forUser($this->staff)->allows('review', $claim));
    }

    public function test_staff_cannot_review_their_own_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->staff->id]);

        $this->assertFalse(
            Gate::forUser($this->staff)->allows('review', $claim),
            'a staff member must not approve their own claim'
        );
    }

    public function test_the_finder_cannot_claim_the_item_they_handed_in(): void
    {
        $item = FoundItem::factory()->for_user($this->owner)->verified()->create();

        $this->assertFalse(Gate::forUser($this->owner)->allows('claim', $item));
        $this->assertTrue(Gate::forUser($this->otherStudent)->allows('claim', $item));
    }

    public function test_an_unverified_item_cannot_be_claimed(): void
    {
        $item = FoundItem::factory()->create(); // pending_verification

        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('claim', $item));
    }

    public function test_a_student_cannot_open_two_claims_on_the_same_item(): void
    {
        $item = FoundItem::factory()->verified()->create();
        Claim::factory()->create([
            'found_item_id' => $item->id,
            'claimant_id' => $this->otherStudent->id,
        ]);

        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('claim', $item->fresh()));
    }

    // -----------------------------------------------------------------
    // Matches
    // -----------------------------------------------------------------

    public function test_a_match_is_private_to_the_student_who_filed_the_lost_report(): void
    {
        $lost = LostItem::factory()->for_user($this->owner)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $lost->id]);

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $match));
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('view', $match));
        $this->assertSame(404, Gate::forUser($this->otherStudent)->inspect('view', $match)->status());
    }

    public function test_only_the_owning_student_can_dismiss_a_match(): void
    {
        $lost = LostItem::factory()->for_user($this->owner)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $lost->id]);

        $this->assertTrue(Gate::forUser($this->owner)->allows('dismiss', $match));
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('dismiss', $match));
        $this->assertFalse(
            Gate::forUser($this->staff)->allows('dismiss', $match),
            'staff have no business dismissing a student suggestion'
        );
    }

    // -----------------------------------------------------------------
    // Users and audit logs
    // -----------------------------------------------------------------

    public function test_only_admins_can_list_users(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($this->staff)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($this->owner)->allows('viewAny', User::class));
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->assertFalse(
            Gate::forUser($this->admin)->allows('suspend', $this->admin),
            'an admin must not be able to lock themselves out'
        );

        $this->assertTrue(Gate::forUser($this->admin)->allows('suspend', $this->owner));
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $this->assertFalse(Gate::forUser($this->admin)->allows('changeRole', $this->admin));
        $this->assertTrue(Gate::forUser($this->admin)->allows('changeRole', $this->owner));
    }

    public function test_staff_cannot_suspend_users(): void
    {
        $this->assertFalse(Gate::forUser($this->staff)->allows('suspend', $this->owner));
    }

    public function test_only_admins_can_read_the_audit_log(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', \App\Models\AuditLog::class));
        $this->assertFalse(Gate::forUser($this->staff)->allows('viewAny', \App\Models\AuditLog::class));
        $this->assertFalse(Gate::forUser($this->owner)->allows('viewAny', \App\Models\AuditLog::class));
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    public function test_nobody_can_read_another_persons_notification_not_even_an_admin(): void
    {
        $notification = new \Illuminate\Notifications\DatabaseNotification([
            'notifiable_id' => $this->owner->id,
            'notifiable_type' => $this->owner->getMorphClass(),
        ]);

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $notification));
        $this->assertFalse(Gate::forUser($this->otherStudent)->allows('view', $notification));
        $this->assertFalse(
            Gate::forUser($this->admin)->allows('view', $notification),
            'an admin should read the audit log, not a student inbox'
        );
    }
}
