<?php

namespace Tests\Feature;

use App\Enums\FoundItemStatus;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\User;
use App\Notifications\FoundItemVerifiedNotification;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FoundItemTest extends TestCase
{
    use RefreshDatabase;

    private User $finder;
    private User $otherStudent;
    private User $staff;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finder = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
        $this->admin = User::factory()->admin()->create();
        Category::factory()->electronics()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Black smartphone',
            'category' => 'electronics',
            'description' => 'Black smartphone handed in at the library desk.',
            'privateVerificationInfo' => 'Lock screen shows a photo of a brown dog.',
            'colour' => 'black',
            // Typed by the finder.
            'location' => 'Bench outside the cafeteria',
            'foundAt' => now()->subDay()->toIso8601String(),
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_a_student_can_hand_in_a_found_item(): void
    {
        Bus::fake();

        $this->actingAs($this->finder)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated()
            // Everything starts unverified, waiting on staff.
            ->assertJsonPath('status', 'pending_verification')
            ->assertJsonPath('reportedBy.role', 'student');
    }

    public function test_an_item_handed_in_by_staff_also_starts_unverified(): void
    {
        Bus::fake();

        // Verification means "I physically have this object", which is a
        // separate act from filing the report.
        $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending_verification');
    }

    public function test_a_student_cannot_self_verify_by_posting_a_status(): void
    {
        Bus::fake();

        $this->actingAs($this->finder)
            ->postJson('/api/found-items', $this->payload(['status' => 'verified']))
            ->assertCreated()
            ->assertJsonPath('status', 'pending_verification');
    }

    // -----------------------------------------------------------------
    // The private verification detail
    // -----------------------------------------------------------------

    public function test_the_finder_and_staff_can_read_the_private_verification_detail(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->verified()->create([
            'private_verification_info' => 'Lock screen shows a brown dog.',
        ]);

        $this->actingAs($this->finder)
            ->getJson("/api/found-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('privateVerificationInfo', 'Lock screen shows a brown dog.');

        $this->actingAs($this->staff)
            ->getJson("/api/found-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('privateVerificationInfo', 'Lock screen shows a brown dog.');
    }

    /**
     * SECURITY: the single most important leak to prevent.
     *
     * If a browsing student could read this, they could recite it back
     * as their own proof of ownership and claim an item that is not
     * theirs.
     */
    public function test_a_browsing_student_never_sees_the_private_verification_detail(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->verified()->create([
            'private_verification_info' => 'Lock screen shows a brown dog.',
        ]);

        $response = $this->actingAs($this->otherStudent)
            ->getJson("/api/found-items/{$item->id}")
            ->assertOk()
            // The item itself is public...
            ->assertJsonPath('title', $item->title);

        // ...but the secret is absent entirely, not merely null.
        $this->assertArrayNotHasKey('privateVerificationInfo', $response->json());
        $this->assertStringNotContainsString('brown dog', $response->getContent());
    }

    public function test_the_private_detail_does_not_leak_through_the_listing_endpoint(): void
    {
        FoundItem::factory()->for_user($this->finder)->verified()->create([
            'private_verification_info' => 'Serial number ends 4417.',
        ]);

        $response = $this->actingAs($this->otherStudent)->getJson('/api/found-items')->assertOk();

        $this->assertStringNotContainsString('4417', $response->getContent());
    }

    public function test_the_private_detail_does_not_leak_through_search(): void
    {
        FoundItem::factory()->for_user($this->finder)->verified()->create([
            'title' => 'Black smartphone',
            'private_verification_info' => 'Serial number ends 4417.',
        ]);

        $response = $this->actingAs($this->otherStudent)
            ->getJson('/api/search?query=smartphone')
            ->assertOk();

        $this->assertStringNotContainsString('4417', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Visibility before verification
    // -----------------------------------------------------------------

    public function test_an_unverified_item_is_hidden_from_other_students_as_a_404(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        // 404 rather than 403, so ids cannot be probed to learn what has
        // been handed in.
        $this->actingAs($this->otherStudent)
            ->getJson("/api/found-items/{$item->id}")
            ->assertStatus(404);
    }

    public function test_the_finder_can_still_see_their_own_unverified_item(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->finder)->getJson("/api/found-items/{$item->id}")->assertOk();
    }

    public function test_the_student_listing_excludes_unverified_items(): void
    {
        FoundItem::factory()->for_user($this->finder)->create(); // pending
        FoundItem::factory()->verified()->create();              // verified

        $response = $this->actingAs($this->otherStudent)->getJson('/api/found-items')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_staff_see_unverified_items_in_the_listing(): void
    {
        FoundItem::factory()->for_user($this->finder)->create();
        FoundItem::factory()->verified()->create();

        $response = $this->actingAs($this->staff)->getJson('/api/found-items')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // -----------------------------------------------------------------
    // Verification
    // -----------------------------------------------------------------

    public function test_staff_can_verify_an_item(): void
    {
        Notification::fake();
        Bus::fake();

        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/verify")
            ->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('verifiedBy.name', $this->staff->name);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ITEM_VERIFIED]);

        // The finder is told their report was accepted.
        Notification::assertSentTo($this->finder, FoundItemVerifiedNotification::class);
    }

    public function test_a_student_cannot_verify_an_item(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/found-items/{$item->id}/verify")
            ->assertStatus(403);

        $this->assertSame(FoundItemStatus::PendingVerification, $item->fresh()->status);
    }

    public function test_a_student_cannot_verify_their_own_item(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        // Otherwise anyone could push a fabricated item into the
        // searchable list.
        $this->actingAs($this->finder)
            ->postJson("/api/found-items/{$item->id}/verify")
            ->assertStatus(403);
    }

    public function test_an_item_cannot_be_verified_twice(): void
    {
        Notification::fake();
        Bus::fake();

        $item = FoundItem::factory()->verified()->create();

        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/verify")
            ->assertStatus(403);
    }

    public function test_staff_can_reject_an_item_with_a_reason(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/reject", ['reason' => 'Duplicate of an earlier report.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create();

        // The finder is told why, so a bare "rejected" is not enough.
        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_verifying_an_item_makes_it_visible_to_students(): void
    {
        Notification::fake();
        Bus::fake();

        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->otherStudent)->getJson("/api/found-items/{$item->id}")->assertStatus(404);

        $this->actingAs($this->staff)->postJson("/api/found-items/{$item->id}/verify")->assertOk();

        $this->actingAs($this->otherStudent)->getJson("/api/found-items/{$item->id}")->assertOk();
    }
}
