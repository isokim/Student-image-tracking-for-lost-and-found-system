<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Events\MatchCreated;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\ItemMatch;
use App\Models\LostItem;
use App\Models\User;
use App\Notifications\PossibleMatchFound;
use App\Services\Matching\MatchFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MatchAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $otherStudent;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as MatchScoringTest: the matchable pair below is
        // built from now(), and a boundary crossing mid-test would change
        // the time score and the resulting match.
        $this->freezeTime();

        $this->student = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
    }

    // -----------------------------------------------------------------
    // Match endpoints
    // -----------------------------------------------------------------

    public function test_a_student_sees_only_their_own_match_suggestions(): void
    {
        $mine = LostItem::factory()->for_user($this->student)->create();
        $theirs = LostItem::factory()->for_user($this->otherStudent)->create();

        ItemMatch::factory()->create(['lost_item_id' => $mine->id]);
        ItemMatch::factory()->count(2)->create(['lost_item_id' => $theirs->id]);

        $response = $this->actingAs($this->student)->getJson('/api/matches')->assertOk();

        $this->assertCount(1, $response->json());
    }

    public function test_a_student_cannot_open_another_students_match(): void
    {
        $theirs = LostItem::factory()->for_user($this->otherStudent)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $theirs->id]);

        // 404: the caller has no way to know this match exists.
        $this->actingAs($this->student)
            ->getJson("/api/matches/{$match->id}")
            ->assertStatus(404);
    }

    public function test_a_student_can_open_and_dismiss_their_own_match(): void
    {
        $mine = LostItem::factory()->for_user($this->student)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $mine->id]);

        $this->actingAs($this->student)
            ->getJson("/api/matches/{$match->id}")
            ->assertOk()
            ->assertJsonPath('id', (string) $match->id)
            // Never "guaranteed".
            ->assertJsonPath('overallConfidence', 'possible');

        $this->actingAs($this->student)
            ->postJson("/api/matches/{$match->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('status', 'dismissed');

        $this->assertSame(MatchStatus::Dismissed, $match->fresh()->status);
    }

    public function test_a_dismissed_match_disappears_from_the_list(): void
    {
        $mine = LostItem::factory()->for_user($this->student)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $mine->id]);

        $this->actingAs($this->student)->postJson("/api/matches/{$match->id}/dismiss")->assertOk();

        $this->assertCount(0, $this->actingAs($this->student)->getJson('/api/matches')->json());
    }

    public function test_staff_cannot_dismiss_a_students_match_on_their_behalf(): void
    {
        $mine = LostItem::factory()->for_user($this->student)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $mine->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/matches/{$match->id}/dismiss")
            ->assertStatus(404);
    }

    public function test_the_score_breakdown_is_returned_so_the_ui_can_explain_the_suggestion(): void
    {
        $mine = LostItem::factory()->for_user($this->student)->create();
        $match = ItemMatch::factory()->create([
            'lost_item_id' => $mine->id,
            'image_score' => 0.82,
            'text_score' => 0.60,
            'category_score' => 1.0,
            'colour_score' => 1.0,
            'location_score' => 1.0,
        ]);

        $this->actingAs($this->student)
            ->getJson("/api/matches/{$match->id}")
            ->assertOk()
            // 0..1 in the database, 0..100 for display.
            ->assertJsonPath('scores.visualSimilarity', 82)
            ->assertJsonPath('scores.descriptionSimilarity', 60)
            ->assertJsonPath('scores.categoryMatch', true)
            ->assertJsonPath('scores.colourMatch', true)
            ->assertJsonPath('scores.locationProximity', 'same');
    }

    // -----------------------------------------------------------------
    // Match creation notifies the right person
    // -----------------------------------------------------------------

    private function makeMatchablePair(): LostItem
    {
        $category = Category::factory()->electronics()->create();

        $lost = LostItem::factory()->create([
            'user_id' => $this->student->id,
            'category_id' => $category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'lost_at' => now()->subDays(2),
        ]);

        FoundItem::factory()->verified()->create([
            'category_id' => $category->id,
            'location_text' => 'Near the library entrance',
            'title' => 'Black iPhone 13',
            'description' => 'Black iPhone with a cracked corner',
            'colour' => 'black',
            'found_at' => now()->subDay(),
        ]);

        return $lost;
    }

    public function test_finding_a_match_notifies_only_the_student_who_filed_the_report(): void
    {
        Notification::fake();
        Event::fake();

        $lost = $this->makeMatchablePair();

        app(MatchFinderService::class)->findForLostItem($lost);

        Notification::assertSentTo($this->student, PossibleMatchFound::class);
        Notification::assertNotSentTo($this->otherStudent, PossibleMatchFound::class);
        Notification::assertNotSentTo($this->staff, PossibleMatchFound::class);

        Event::assertDispatched(MatchCreated::class);
    }

    public function test_rerunning_the_matcher_does_not_notify_the_student_twice(): void
    {
        Notification::fake();
        Event::fake();

        $lost = $this->makeMatchablePair();

        $finder = app(MatchFinderService::class);
        $finder->findForLostItem($lost);
        $finder->findForLostItem($lost->fresh());

        // The second pass updates the score but must not re-alert.
        Notification::assertSentToTimes($this->student, PossibleMatchFound::class, 1);
        $this->assertSame(1, ItemMatch::count());
    }

    public function test_the_match_event_broadcasts_on_the_owners_private_channel_only(): void
    {
        $lost = LostItem::factory()->for_user($this->student)->create();
        $match = ItemMatch::factory()->create(['lost_item_id' => $lost->id]);

        $channels = (new MatchCreated($match->load('lostItem')))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-users.'.$this->student->id, $channels[0]->name);
    }

    public function test_the_match_broadcast_payload_carries_no_private_detail(): void
    {
        $lost = LostItem::factory()->for_user($this->student)->create();
        $found = FoundItem::factory()->verified()->create([
            'private_verification_info' => 'Serial ends 4417.',
            'description' => 'A very identifiable description.',
        ]);
        $match = ItemMatch::factory()->create([
            'lost_item_id' => $lost->id,
            'found_item_id' => $found->id,
        ]);

        $payload = json_encode(
            (new MatchCreated($match->load(['lostItem', 'foundItem'])))->broadcastWith()
        );

        // Ids and a title for the toast, nothing more - a websocket
        // payload bypasses every policy, so it must carry nothing
        // sensitive in the first place.
        $this->assertStringNotContainsString('4417', $payload);
        $this->assertStringNotContainsString('very identifiable', $payload);
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    public function test_a_student_sees_only_their_own_notifications(): void
    {
        $this->student->notify(new PossibleMatchFound(
            ItemMatch::factory()->create([
                'lost_item_id' => LostItem::factory()->for_user($this->student)->create()->id,
            ])
        ));

        $this->otherStudent->notify(new PossibleMatchFound(
            ItemMatch::factory()->create([
                'lost_item_id' => LostItem::factory()->for_user($this->otherStudent)->create()->id,
            ])
        ));

        $response = $this->actingAs($this->student)->getJson('/api/notifications')->assertOk();

        $this->assertCount(1, $response->json());

        // Matches the AppNotification interface in the React types.
        $response->assertJsonStructure([['id', 'type', 'title', 'body', 'readAt', 'relatedResourceUrl', 'createdAt']]);
    }

    public function test_the_database_holds_the_notification_even_though_reverb_also_broadcasts(): void
    {
        $match = ItemMatch::factory()->create([
            'lost_item_id' => LostItem::factory()->for_user($this->student)->create()->id,
        ]);

        $this->student->notify(new PossibleMatchFound($match));

        // The websocket is delivery; the database is the source of truth,
        // so a student who had no tab open still sees this on next load.
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('possible_match', $this->student->notifications()->first()->data['type']);
    }

    public function test_a_student_can_mark_their_own_notification_read(): void
    {
        $match = ItemMatch::factory()->create([
            'lost_item_id' => LostItem::factory()->for_user($this->student)->create()->id,
        ]);
        $this->student->notify(new PossibleMatchFound($match));

        $notification = $this->student->notifications()->first();

        $this->actingAs($this->student)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('readAt', fn ($value) => $value !== null);
    }

    public function test_a_student_cannot_mark_another_students_notification_read(): void
    {
        $match = ItemMatch::factory()->create([
            'lost_item_id' => LostItem::factory()->for_user($this->student)->create()->id,
        ]);
        $this->student->notify(new PossibleMatchFound($match));

        $notification = $this->student->notifications()->first();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_the_callers_notifications(): void
    {
        foreach ([$this->student, $this->otherStudent] as $user) {
            $match = ItemMatch::factory()->create([
                'lost_item_id' => LostItem::factory()->for_user($user)->create()->id,
            ]);
            $user->notify(new PossibleMatchFound($match));
        }

        $this->actingAs($this->student)->postJson('/api/notifications/read-all')->assertOk();

        $this->assertCount(0, $this->student->fresh()->unreadNotifications);
        $this->assertCount(1, $this->otherStudent->fresh()->unreadNotifications);
    }

    public function test_the_notification_wording_is_tentative_not_a_promise(): void
    {
        $match = ItemMatch::factory()->create([
            'lost_item_id' => LostItem::factory()->for_user($this->student)->create()->id,
        ]);
        $this->student->notify(new PossibleMatchFound($match));

        $data = $this->student->notifications()->first()->data;

        // Telling a student their laptop has been found, and then having
        // the claim rejected, is worse than saying nothing.
        $this->assertStringContainsString('Possible match', $data['title']);
        $this->assertStringContainsString('might be', $data['body']);
        $this->assertStringNotContainsString('found your', $data['body']);
    }
}
