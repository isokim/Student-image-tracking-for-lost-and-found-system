<?php

namespace Tests\Feature;

use App\Enums\FoundItemStatus;
use App\Models\Category;
use App\Events\FoundItemVerified;
use App\Jobs\FindPotentialMatches;
use App\Jobs\ProcessItemImage;
use App\Models\FoundItem;
use App\Models\LostItem;
use App\Models\User;
use App\Notifications\FoundItemVerifiedNotification;
use App\Notifications\PossibleMatchFound;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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

    public function test_a_student_cannot_log_a_found_item(): void
    {
        Bus::fake();

        // Students hand items in at the office; staff log them. Letting
        // students post found items let anyone fabricate one.
        $this->actingAs($this->finder)
            ->postJson('/api/found-items', $this->payload())
            ->assertStatus(403);

        $this->assertDatabaseCount('found_items', 0);
    }

    public function test_the_found_item_policy_refuses_students_even_without_the_route_middleware(): void
    {
        $this->assertFalse($this->finder->can('create', FoundItem::class));
        $this->assertTrue($this->staff->can('create', FoundItem::class));
        $this->assertTrue($this->admin->can('create', FoundItem::class));
    }

    public function test_staff_can_log_a_found_item(): void
    {
        Bus::fake();

        $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated()
            ->assertJsonPath('reportedBy.role', 'staff');
    }

    public function test_an_admin_can_log_a_found_item(): void
    {
        Bus::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated();
    }

    public function test_an_item_logged_by_staff_is_verified_straight_away(): void
    {
        Bus::fake();

        // Staff log items with the object in hand at the office, which is
        // what verification confirms - so there is no second sign-off.
        $response = $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'verified');

        $item = FoundItem::findOrFail($response->json('id'));

        $this->assertSame($this->staff->id, $item->verified_by);
        $this->assertNotNull($item->verified_at);
        $this->assertTrue($item->status->isClaimable());

        $this->assertDatabaseHas('item_status_history', [
            'item_id' => $item->id,
            'from_status' => null,
            'to_status' => 'verified',
            'changed_by' => $this->staff->id,
            'note' => 'Logged by staff at the office.',
        ]);
    }

    public function test_creating_a_found_item_as_staff_dispatches_matching_once(): void
    {
        Bus::fake();
        Event::fake([FoundItemVerified::class]);

        $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated();

        // The photo pipeline ends in FindPotentialMatches; it is not also
        // dispatched directly, so matching runs exactly once.
        Bus::assertDispatchedTimes(ProcessItemImage::class, 1);
        Bus::assertNotDispatched(FindPotentialMatches::class);
        Event::assertDispatchedTimes(FoundItemVerified::class, 1);
    }

    public function test_logging_a_found_item_matches_it_and_notifies_the_lost_report_owner(): void
    {
        Notification::fake();
        $this->freezeTime();

        $owner = User::factory()->student()->create();

        LostItem::factory()->create([
            'user_id' => $owner->id,
            'category_id' => Category::where('slug', 'electronics')->value('id'),
            'title' => 'Black smartphone',
            'description' => 'Black smartphone handed in at the library desk.',
            'colour' => 'black',
            'location_text' => 'Bench outside the cafeteria',
            'lost_at' => now()->subDays(2),
        ]);

        // The queue is sync in tests, so the pipeline and matching run for real.
        $response = $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('item_matches', ['found_item_id' => $response->json('id')]);
        Notification::assertSentToTimes($owner, PossibleMatchFound::class, 1);
        // The staff member logged it themselves; no "accepted" notice.
        Notification::assertNotSentTo($this->staff, FoundItemVerifiedNotification::class);
    }

    public function test_a_status_posted_with_a_new_item_is_ignored(): void
    {
        Bus::fake();

        // The server decides the starting status; a posted one is ignored.
        $this->actingAs($this->staff)
            ->postJson('/api/found-items', $this->payload(['status' => 'returned']))
            ->assertCreated()
            ->assertJsonPath('status', 'verified');
    }

    // -----------------------------------------------------------------
    // The private verification detail
    // -----------------------------------------------------------------

    public function test_the_hidden_verification_detail_is_required_when_handing_in(): void
    {
        Bus::fake();

        $without = $this->payload();
        unset($without['privateVerificationInfo']);

        foreach ([$this->staff, $this->admin] as $user) {
            $this->actingAs($user)
                ->postJson('/api/found-items', $without)
                ->assertStatus(422)
                ->assertJsonValidationErrors('privateVerificationInfo');

            $this->actingAs($user)
                ->postJson('/api/found-items', $this->payload(['privateVerificationInfo' => 'too short']))
                ->assertStatus(422)
                ->assertJsonValidationErrors('privateVerificationInfo');
        }

        $this->assertDatabaseCount('found_items', 0);
    }

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

    public function test_an_empty_search_lists_recent_found_items_newest_first(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Older', 'found_at' => now()->subDays(5)]);
        FoundItem::factory()->verified()->create(['title' => 'Newer', 'found_at' => now()->subDay()]);
        FoundItem::factory()->create(['title' => 'Rejected', 'status' => 'rejected']);

        // What the search page sends when nothing is filled in.
        $response = $this->actingAs($this->otherStudent)->getJson('/api/search')->assertOk();

        $this->assertSame(['Newer', 'Older'], array_column($response->json(), 'title'));
    }

    public function test_the_search_date_range_includes_the_whole_last_day(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Afternoon', 'found_at' => '2026-03-10 15:30:00']);
        FoundItem::factory()->verified()->create(['title' => 'Next day', 'found_at' => '2026-03-11 09:00:00']);

        $response = $this->actingAs($this->otherStudent)
            ->getJson('/api/search?dateFrom=2026-03-10&dateTo=2026-03-10')
            ->assertOk();

        $this->assertSame(['Afternoon'], array_column($response->json(), 'title'));
    }

    // -----------------------------------------------------------------
    // Visibility before verification
    // -----------------------------------------------------------------

    public function test_an_unverified_item_is_visible_to_other_students(): void
    {
        // Visible so a match can point at it straight away; claiming
        // still waits for staff to verify it.
        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->actingAs($this->otherStudent)
            ->getJson("/api/found-items/{$item->id}")
            ->assertOk();
    }

    public function test_a_rejected_item_is_hidden_from_other_students_as_a_404(): void
    {
        $item = FoundItem::factory()->for_user($this->finder)->create(['status' => 'rejected']);

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

    public function test_the_student_listing_includes_unverified_but_excludes_rejected_items(): void
    {
        FoundItem::factory()->for_user($this->finder)->create();                            // pending
        FoundItem::factory()->verified()->create();                                         // verified
        FoundItem::factory()->for_user($this->finder)->create(['status' => 'rejected']);    // rejected

        $response = $this->actingAs($this->otherStudent)->getJson('/api/found-items')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_staff_see_unverified_items_in_the_listing(): void
    {
        FoundItem::factory()->for_user($this->finder)->create();
        FoundItem::factory()->verified()->create();

        $response = $this->actingAs($this->staff)->getJson('/api/found-items')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_listing_can_be_filtered_by_keyword(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Blue backpack', 'description' => 'Plain.', 'location_text' => 'Gym']);
        FoundItem::factory()->verified()->create(['title' => 'Keys', 'description' => 'On a blue lanyard.', 'location_text' => 'Gym']);
        FoundItem::factory()->verified()->create(['title' => 'Umbrella', 'description' => 'Plain.', 'location_text' => 'Blue Hall']);
        FoundItem::factory()->verified()->create(['title' => 'Wallet', 'description' => 'Black leather.', 'location_text' => 'Library']);

        $response = $this->actingAs($this->otherStudent)
            ->getJson('/api/found-items?q=blue')
            ->assertOk();

        // Title, description and location are all searched.
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_keyword_wildcards_are_treated_as_plain_text(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Wallet']);

        $this->actingAs($this->otherStudent)
            ->getJson('/api/found-items?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_listing_can_be_filtered_by_category(): void
    {
        $bags = Category::factory()->create(['slug' => 'bags', 'name' => 'Bags']);
        FoundItem::factory()->verified()->create(['category_id' => $bags->id]);
        FoundItem::factory()->verified()->create([
            'category_id' => Category::where('slug', 'electronics')->value('id'),
        ]);

        $this->actingAs($this->otherStudent)
            ->getJson('/api/found-items?category=bags')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // An unknown slug matches nothing rather than everything.
        $this->actingAs($this->otherStudent)
            ->getJson('/api/found-items?category=not-a-category')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filters_do_not_widen_what_a_student_can_see(): void
    {
        FoundItem::factory()->create(['title' => 'Blue backpack', 'status' => 'rejected']);

        $this->actingAs($this->otherStudent)
            ->getJson('/api/found-items?q=blue')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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

    public function test_staff_can_take_down_a_verified_item(): void
    {
        // Staff-logged items start verified, so this is how one logged by
        // mistake is removed.
        $item = FoundItem::factory()->verified($this->staff)->create();

        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/reject", ['reason' => 'Logged twice by mistake.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->actingAs($this->otherStudent)->getJson("/api/found-items/{$item->id}")->assertStatus(404);
    }

    public function test_a_returned_item_cannot_be_rejected(): void
    {
        $item = FoundItem::factory()->returned()->create();

        $this->actingAs($this->staff)
            ->postJson("/api/found-items/{$item->id}/reject", ['reason' => 'Too late.'])
            ->assertForbidden();
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

    public function test_verifying_an_item_makes_it_claimable(): void
    {
        Notification::fake();
        Bus::fake();

        $item = FoundItem::factory()->for_user($this->finder)->create();

        $this->assertFalse($this->otherStudent->can('claim', $item));

        $this->actingAs($this->staff)->postJson("/api/found-items/{$item->id}/verify")->assertOk();

        $this->assertTrue($this->otherStudent->can('claim', $item->fresh()));
    }
}
