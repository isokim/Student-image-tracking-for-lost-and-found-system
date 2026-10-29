<?php

namespace Tests\Feature;

use App\Jobs\ProcessItemImage;
use App\Models\Category;
use App\Models\LostItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LostItemTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $otherStudent;
    private User $staff;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
        $this->category = Category::factory()->electronics()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Black iPhone 13',
            'category' => 'electronics',
            'description' => 'Black iPhone with a cracked top-right corner.',
            'colour' => 'black',
            // The place is typed by the reporter now, not picked from the
            // locations list.
            'location' => 'Near the library entrance',
            'lostAt' => now()->subDays(2)->toIso8601String(),
            'additionalInfo' => 'Lock screen is a photo of my dog.',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_a_student_can_report_a_lost_item(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('title', 'Black iPhone 13')
            ->assertJsonPath('category', 'electronics')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('reportedBy.name', $this->student->name)
            ->assertJsonPath('locationText', 'Near the library entrance');

        $this->assertDatabaseHas('lost_items', [
            'title' => 'Black iPhone 13',
            'user_id' => $this->student->id,
            'location_text' => 'Near the library entrance',
        ]);
    }

    /**
     * SECURITY: ownership comes from the session, not the payload.
     */
    public function test_a_student_cannot_file_a_report_in_someone_elses_name(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload([
                'user_id' => $this->otherStudent->id,
                'userId' => $this->otherStudent->id,
            ]))
            ->assertCreated();

        $this->assertSame($this->student->id, LostItem::first()->user_id);
    }

    public function test_a_student_cannot_set_the_status_of_a_new_report(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload(['status' => 'resolved']))
            ->assertCreated()
            ->assertJsonPath('status', 'active');
    }

    public function test_creating_a_report_records_an_audit_entry_and_a_status_history_row(): void
    {
        $this->actingAs($this->student)->postJson('/api/lost-items', $this->payload())->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ITEM_CREATED,
            'entity_type' => 'lost_item',
            'user_id' => $this->student->id,
        ]);

        $this->assertDatabaseHas('item_status_history', [
            'item_type' => 'lost_item',
            'to_status' => 'active',
        ]);
    }

    public function test_a_guest_cannot_report_a_lost_item(): void
    {
        $this->postJson('/api/lost-items', $this->payload())->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    public function test_a_report_requires_the_core_fields(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'category', 'description', 'location', 'lostAt']);
    }

    public function test_a_report_rejects_an_unknown_category(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload(['category' => 'spaceships']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_a_report_rejects_a_future_lost_date(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload(['lostAt' => now()->addWeek()->toIso8601String()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lostAt');
    }

    /**
     * The form sends a bare date and the app runs in UTC, so a student
     * three hours ahead can pick "today" and have the server read it as
     * tomorrow. That must not be rejected - it is the common case at
     * 01:00, not a mistake.
     */
    public function test_a_date_that_is_today_ahead_of_utc_is_accepted(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload(['lostAt' => now()->addDay()->toDateString()]))
            ->assertCreated()
            ->assertJsonPath('locationText', 'Near the library entrance');
    }

    public function test_a_report_rejects_an_empty_location(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/lost-items', $this->payload(['location' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location');
    }

    // -----------------------------------------------------------------
    // File upload security
    // -----------------------------------------------------------------

    public function test_a_valid_photo_is_accepted_and_stored_on_the_private_disk(): void
    {
        Storage::fake('items');
        Bus::fake();

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload([
                'photo' => UploadedFile::fake()->image('phone.jpg', 800, 600),
            ]))
            ->assertCreated();

        $this->assertDatabaseCount('item_images', 1);

        $image = \App\Models\ItemImage::first();

        // Stored on the private disk, never a public one.
        $this->assertSame('items', $image->disk);
        Storage::disk('items')->assertExists($image->path);

        // AI work is queued, not run during the request.
        Bus::assertDispatched(ProcessItemImage::class);
    }

    /**
     * SECURITY: the stored filename is generated by the server.
     */
    public function test_the_original_filename_is_never_used_to_build_the_stored_path(): void
    {
        Storage::fake('items');
        Bus::fake();

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload([
                'photo' => UploadedFile::fake()->image('../../../evil shell.php.jpg', 400, 400),
            ]))
            ->assertCreated();

        $image = \App\Models\ItemImage::first();

        // The path is a uuid under the item's own folder - no traversal,
        // no attacker-chosen extension.
        $this->assertStringNotContainsString('..', $image->path);
        $this->assertStringNotContainsString('evil', $image->path);
        $this->assertStringEndsWith('.jpg', $image->path);
        $this->assertMatchesRegularExpression(
            '#^lost_item/\d+/[0-9a-f-]{36}\.jpg$#',
            $image->path,
        );
    }

    public function test_a_php_file_disguised_as_an_image_is_rejected(): void
    {
        Storage::fake('items');

        $malicious = UploadedFile::fake()->createWithContent(
            'photo.jpg',
            "<?php system(\$_GET['cmd']); ?>",
        );

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload(['photo' => $malicious]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('item_images', 0);
    }

    public function test_a_non_image_file_type_is_rejected(): void
    {
        Storage::fake('items');

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload([
                'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_a_gif_is_rejected_because_only_jpeg_png_and_webp_are_allowed(): void
    {
        Storage::fake('items');

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload([
                'photo' => UploadedFile::fake()->image('animation.gif'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        Storage::fake('items');

        // 9MB, over the 8MB ceiling the React uploader also enforces.
        $huge = UploadedFile::fake()->create('huge.jpg', 9 * 1024, 'image/jpeg');

        $this->actingAs($this->student)
            ->post('/api/lost-items', $this->payload(['photo' => $huge]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('item_images', 0);
    }

    // -----------------------------------------------------------------
    // Reading - the IDOR rule from the brief
    // -----------------------------------------------------------------

    public function test_the_board_is_browsable_by_any_signed_in_student(): void
    {
        LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->otherStudent)
            ->getJson('/api/lost-items')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'perPage', 'total']]);
    }

    /**
     * SECURITY: the brief's headline rule - GET /api/lost-items/25.
     */
    public function test_another_students_private_notes_are_not_returned_when_changing_the_id(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create([
            'additional_info' => 'Lock screen is a photo of my dog.',
        ]);

        // The owner sees their own notes.
        $this->actingAs($this->student)
            ->getJson("/api/lost-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('additionalInfo', 'Lock screen is a photo of my dog.');

        // Another student sees the report, but not the private detail -
        // the key is absent entirely, not null.
        $response = $this->actingAs($this->otherStudent)
            ->getJson("/api/lost-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('title', $item->title);

        $this->assertArrayNotHasKey('additionalInfo', $response->json());
        $this->assertStringNotContainsString('photo of my dog', $response->getContent());
    }

    public function test_staff_can_see_the_private_notes(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create([
            'additional_info' => 'Secret detail',
        ]);

        $this->actingAs($this->staff)
            ->getJson("/api/lost-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('additionalInfo', 'Secret detail');
    }

    public function test_mine_returns_only_the_callers_own_reports(): void
    {
        LostItem::factory()->for_user($this->student)->count(2)->create();
        LostItem::factory()->for_user($this->otherStudent)->count(3)->create();

        $response = $this->actingAs($this->student)->getJson('/api/lost-items/mine')->assertOk();

        $this->assertCount(2, $response->json());
    }

    // -----------------------------------------------------------------
    // Updating and closing
    // -----------------------------------------------------------------

    public function test_a_student_cannot_edit_another_students_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->otherStudent)
            ->putJson("/api/lost-items/{$item->id}", ['title' => 'Hijacked title'])
            ->assertStatus(403);

        $this->assertNotSame('Hijacked title', $item->fresh()->title);
    }

    public function test_the_owner_can_edit_their_own_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->student)
            ->putJson("/api/lost-items/{$item->id}", ['title' => 'Updated title'])
            ->assertOk()
            ->assertJsonPath('title', 'Updated title');
    }

    public function test_a_student_cannot_close_another_students_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/lost-items/{$item->id}/close")
            ->assertStatus(403);
    }

    public function test_the_owner_can_close_their_own_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->student)
            ->postJson("/api/lost-items/{$item->id}/close")
            ->assertOk()
            ->assertJsonPath('status', 'closed');
    }

    public function test_a_student_cannot_delete_another_students_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create();

        $this->actingAs($this->otherStudent)
            ->deleteJson("/api/lost-items/{$item->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('lost_items', ['id' => $item->id, 'deleted_at' => null]);
    }
}
