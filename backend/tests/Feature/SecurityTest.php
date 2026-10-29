<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\FoundItem;
use App\Models\ItemImage;
use App\Models\LostItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The security guarantees the brief lists explicitly, each as a test.
 *
 * These overlap with the per-feature tests on purpose. This file is the
 * checklist: if someone changes a policy six months from now, the
 * failure here says plainly which promise was broken.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $otherStudent;
    private User $staff;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->otherStudent = User::factory()->student()->create();
        $this->staff = User::factory()->staff()->create();
        $this->admin = User::factory()->admin()->create();
    }

    // =================================================================
    // 1. Unauthenticated users cannot reach protected endpoints
    // =================================================================

    public static function protectedEndpoints(): array
    {
        return [
            'current user' => ['get', '/api/user'],
            'lost items' => ['get', '/api/lost-items'],
            'my lost items' => ['get', '/api/lost-items/mine'],
            'create lost item' => ['post', '/api/lost-items'],
            'found items' => ['get', '/api/found-items'],
            'create found item' => ['post', '/api/found-items'],
            'claims' => ['get', '/api/claims'],
            'create claim' => ['post', '/api/claims'],
            'matches' => ['get', '/api/matches'],
            'notifications' => ['get', '/api/notifications'],
            'search' => ['get', '/api/search'],
            'image search' => ['post', '/api/search/image'],
            'categories' => ['get', '/api/categories'],
            'staff dashboard' => ['get', '/api/staff/dashboard'],
            'staff claims' => ['get', '/api/staff/claims'],
            'admin dashboard' => ['get', '/api/admin/dashboard'],
            'admin stats' => ['get', '/api/admin/stats'],
            'admin users' => ['get', '/api/admin/users'],
            'admin audit logs' => ['get', '/api/admin/audit-logs'],
            'admin categories' => ['get', '/api/admin/categories'],
            'create staff' => ['post', '/api/admin/staff'],
        ];
    }

    /**
     * @dataProvider protectedEndpoints
     */
    public function test_a_guest_is_rejected_from_every_protected_endpoint(string $method, string $uri): void
    {
        $this->{$method.'Json'}($uri)
            ->assertStatus(401)
            // JSON, never a redirect to a login page - the React
            // AuthContext relies on a 401 to detect an expired session.
            ->assertJsonPath('message', 'You must be signed in to do that.');
    }

    // =================================================================
    // 2. Students cannot reach admin endpoints
    // =================================================================

    public static function adminEndpoints(): array
    {
        return [
            'dashboard' => ['get', '/api/admin/dashboard'],
            'stats' => ['get', '/api/admin/stats'],
            'users' => ['get', '/api/admin/users'],
            'audit logs' => ['get', '/api/admin/audit-logs'],
            'categories' => ['get', '/api/admin/categories'],
            'create category' => ['post', '/api/admin/categories'],
            'create staff' => ['post', '/api/admin/staff'],
            'admin lost items' => ['get', '/api/admin/lost-items'],
            'admin found items' => ['get', '/api/admin/found-items'],
            'admin claims' => ['get', '/api/admin/claims'],
        ];
    }

    /**
     * @dataProvider adminEndpoints
     */
    public function test_a_student_cannot_reach_an_admin_endpoint(string $method, string $uri): void
    {
        $this->actingAs($this->student)
            ->{$method.'Json'}($uri)
            ->assertStatus(403);
    }

    /**
     * @dataProvider adminEndpoints
     */
    public function test_a_staff_member_cannot_reach_an_admin_endpoint(string $method, string $uri): void
    {
        // Staff are not junior admins: they work the counter, they do not
        // manage accounts or read the audit log.
        $this->actingAs($this->staff)
            ->{$method.'Json'}($uri)
            ->assertStatus(403);
    }

    public function test_an_admin_can_reach_admin_endpoints(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/admin/audit-logs')->assertOk();
    }

    public function test_the_admin_denial_does_not_describe_what_lives_there(): void
    {
        $response = $this->actingAs($this->student)->getJson('/api/admin/users')->assertStatus(403);

        // No "admins only", no hint that an admin area exists here.
        $this->assertSame('You are not allowed to do that.', $response->json('message'));
    }

    // =================================================================
    // 3. Students cannot reach staff endpoints
    // =================================================================

    public function test_a_student_cannot_reach_staff_endpoints(): void
    {
        $this->actingAs($this->student)->getJson('/api/staff/dashboard')->assertStatus(403);
        $this->actingAs($this->student)->getJson('/api/staff/claims')->assertStatus(403);
        $this->actingAs($this->student)->getJson('/api/staff/found-items')->assertStatus(403);
    }

    public function test_staff_and_admins_can_reach_staff_endpoints(): void
    {
        $this->actingAs($this->staff)->getJson('/api/staff/dashboard')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/staff/dashboard')->assertOk();
    }

    // =================================================================
    // 4. Students cannot modify another student's reports (IDOR)
    // =================================================================

    public function test_a_student_cannot_modify_another_students_lost_report(): void
    {
        $item = LostItem::factory()->for_user($this->student)->create(['title' => 'Original']);

        $this->actingAs($this->otherStudent)
            ->putJson("/api/lost-items/{$item->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($this->otherStudent)
            ->deleteJson("/api/lost-items/{$item->id}")
            ->assertStatus(403);

        $this->actingAs($this->otherStudent)
            ->postJson("/api/lost-items/{$item->id}/close")
            ->assertStatus(403);

        $this->assertSame('Original', $item->fresh()->title);
    }

    public function test_a_student_cannot_modify_another_students_found_item(): void
    {
        $item = FoundItem::factory()->for_user($this->student)->create(['title' => 'Original']);

        $this->actingAs($this->otherStudent)
            ->putJson("/api/found-items/{$item->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Original', $item->fresh()->title);
    }

    // =================================================================
    // 5. Students cannot access private claim information
    // =================================================================

    public function test_a_student_cannot_read_or_act_on_another_students_claim(): void
    {
        $claim = Claim::factory()->create(['claimant_id' => $this->student->id]);

        // 404, because a 403 would confirm the claim exists.
        $this->actingAs($this->otherStudent)->getJson("/api/claims/{$claim->id}")->assertStatus(404);

        $this->actingAs($this->otherStudent)
            ->postJson("/api/claims/{$claim->id}/approve")->assertStatus(403);
        $this->actingAs($this->otherStudent)
            ->postJson("/api/claims/{$claim->id}/reject", ['notes' => 'nope'])->assertStatus(403);
    }

    public function test_enumerating_claim_ids_reveals_nothing(): void
    {
        $realClaim = Claim::factory()->create(['claimant_id' => $this->student->id]);
        $missingId = $realClaim->id + 9999;

        // An existing-but-forbidden claim and a non-existent one must be
        // indistinguishable, or ids can be probed.
        $existing = $this->actingAs($this->otherStudent)->getJson("/api/claims/{$realClaim->id}");
        $missing = $this->actingAs($this->otherStudent)->getJson("/api/claims/{$missingId}");

        $this->assertSame($existing->status(), $missing->status());
        $this->assertSame($existing->json('message'), $missing->json('message'));
    }

    // =================================================================
    // 6. Unauthorized users cannot access private images
    // =================================================================

    public function test_a_student_cannot_fetch_the_photo_of_an_unverified_found_item(): void
    {
        Storage::fake('items');

        $item = FoundItem::factory()->for_user($this->student)->create();
        // Created through the relationship, which is how the real
        // code does it - item_type/item_id are not fillable, so a
        // direct ItemImage::create() would silently drop them.
        $image = $item->images()->create([
    'disk' => 'items',
                'path' => 'found_item/'.$item->id.'/secret.jpg',
                'original_name' => 'passport.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
        ]);
        Storage::disk('items')->put($image->path, 'fake-bytes');

        // 404: the image is as invisible as the item it belongs to.
        $this->actingAs($this->otherStudent)
            ->getJson("/api/item-images/{$image->id}")
            ->assertStatus(404);

        // The finder can fetch it.
        $this->actingAs($this->student)
            ->get("/api/item-images/{$image->id}")
            ->assertOk();
    }

    public function test_images_are_not_reachable_without_signing_in(): void
    {
        Storage::fake('items');

        $item = FoundItem::factory()->verified()->create();
        // Created through the relationship, which is how the real
        // code does it - item_type/item_id are not fillable, so a
        // direct ItemImage::create() would silently drop them.
        $image = $item->images()->create([
    'disk' => 'items',
                'path' => 'found_item/'.$item->id.'/photo.jpg',
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
        ]);
        Storage::disk('items')->put($image->path, 'fake-bytes');

        $this->getJson("/api/item-images/{$image->id}")->assertStatus(401);
    }

    public function test_image_responses_carry_nosniff_and_private_cache_headers(): void
    {
        Storage::fake('items');

        $item = FoundItem::factory()->verified()->create();
        // Created through the relationship, which is how the real
        // code does it - item_type/item_id are not fillable, so a
        // direct ItemImage::create() would silently drop them.
        $image = $item->images()->create([
    'disk' => 'items',
                'path' => 'found_item/'.$item->id.'/photo.jpg',
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
        ]);
        Storage::disk('items')->put($image->path, 'fake-bytes');

        $response = $this->actingAs($this->student)->get("/api/item-images/{$image->id}")->assertOk();

        // nosniff stops a browser second-guessing the type and executing
        // the file; private stops a shared proxy caching one user's
        // authorised photo and serving it to another.
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_the_stored_file_path_is_never_exposed_in_json(): void
    {
        $item = FoundItem::factory()->verified()->create();
        // Created through the relationship, which is how the real
        // code does it - item_type/item_id are not fillable, so a
        // direct ItemImage::create() would silently drop them.
        $image = $item->images()->create([
    'disk' => 'items',
                'path' => 'found_item/'.$item->id.'/secret-path.jpg',
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
        ]);

        $response = $this->actingAs($this->student)
            ->getJson("/api/found-items/{$item->id}")
            ->assertOk();

        // The client gets an authorised route URL, never a disk path.
        // Asserted on the decoded value rather than the raw body, because
        // JSON escapes the slashes.
        $this->assertStringNotContainsString('secret-path', $response->getContent());
        $this->assertStringEndsWith("/api/item-images/{$image->id}", $response->json('photo.url'));
    }

    // =================================================================
    // 7. Role values cannot be manipulated
    // =================================================================

    public function test_a_user_cannot_escalate_their_role_through_any_endpoint(): void
    {
        // Registration.
        $this->postJson('/api/register', [
            'name' => 'Escalator',
            'email' => 'esc@test.test',
            'admissionNumber' => 'BIT/2024/9999',
            'password' => 'Password123',
            'passwordConfirmation' => 'Password123',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertTrue(User::where('email', 'esc@test.test')->firstOrFail()->isStudent());

        // The admin role-change endpoint.
        $this->actingAs($this->student)
            ->postJson("/api/admin/users/{$this->student->id}/role", ['role' => 'admin'])
            ->assertStatus(403);

        $this->assertTrue($this->student->fresh()->isStudent());
    }

    public function test_an_admin_cannot_change_their_own_role_or_suspend_themselves(): void
    {
        // Guards against an administrator locking the system's last admin
        // out of it.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->admin->id}/role", ['role' => 'student'])
            ->assertStatus(403);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->admin->id}/suspend")
            ->assertStatus(403);

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_only_staff_and_admin_roles_can_be_provisioned_through_the_admin_endpoint(): void
    {
        $department = \App\Models\Department::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/staff', [
                'name' => 'New Person',
                'email' => 'new@staff.test',
                'role' => 'student',
                'departmentId' => $department->id,
                'password' => 'Password123',
                'passwordConfirmation' => 'Password123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_an_admin_can_provision_a_staff_account(): void
    {
        $department = \App\Models\Department::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/staff', [
                'name' => 'New Staff',
                'email' => 'new@staff.test',
                'role' => 'staff',
                'departmentId' => $department->id,
                'password' => 'Password123',
                'passwordConfirmation' => 'Password123',
            ])
            ->assertCreated()
            ->assertJsonPath('role', 'staff');

        $this->assertDatabaseHas('audit_logs', ['action' => \App\Services\AuditLogger::STAFF_CREATED]);
    }

    // =================================================================
    // 8. Suspension takes effect immediately
    // =================================================================

    public function test_suspending_a_user_ends_their_access_at_once(): void
    {
        $victim = User::factory()->student()->create();

        $this->actingAs($victim)->getJson('/api/user')->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$victim->id}/suspend")
            ->assertOk();

        // Not at next login - on the very next request.
        $this->actingAs($victim->fresh())->getJson('/api/user')->assertStatus(403);
    }

    // =================================================================
    // 9. Injection and mass assignment
    // =================================================================

    public function test_a_sql_injection_attempt_in_search_is_treated_as_a_plain_term(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Black phone']);

        $this->actingAs($this->student)
            ->getJson('/api/search?query='.urlencode("'; DROP TABLE found_items; --"))
            ->assertOk();

        // The table is still there, and the term simply matched nothing.
        $this->assertDatabaseCount('found_items', 1);
    }

    public function test_like_wildcards_in_a_search_term_do_not_match_everything(): void
    {
        FoundItem::factory()->verified()->create(['title' => 'Black phone', 'description' => 'A black phone']);
        FoundItem::factory()->verified()->create(['title' => 'Red umbrella', 'description' => 'A red umbrella']);

        // "%" must be a literal character, not "match everything".
        $response = $this->actingAs($this->student)->getJson('/api/search?query=%25')->assertOk();

        $this->assertCount(0, $response->json());
    }

    public function test_timestamps_and_ids_cannot_be_mass_assigned(): void
    {
        Bus::fake();

        \App\Models\Category::factory()->electronics()->create();

        $this->actingAs($this->student)->postJson('/api/lost-items', [
            'title' => 'Black iPhone',
            'category' => 'electronics',
            'description' => 'A black iPhone with a cracked screen.',
            'location' => 'Near the main gate',
            'lostAt' => now()->subDay()->toIso8601String(),
            'id' => 9999,
            'created_at' => '2001-01-01 00:00:00',
            'ai_processed_at' => now()->toIso8601String(),
        ])->assertCreated();

        $item = LostItem::first();

        $this->assertNotSame(9999, $item->id);
        $this->assertNotSame('2001', $item->created_at->format('Y'));
        $this->assertNull($item->ai_processed_at);
    }

    // =================================================================
    // 10. Upload restrictions
    // =================================================================

    public function test_an_executable_disguised_as_an_image_is_rejected_on_both_item_types(): void
    {
        Storage::fake('items');

        \App\Models\Category::factory()->electronics()->create();

        $malicious = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "pwned"; ?>');

        $this->actingAs($this->student)->post('/api/lost-items', [
            'title' => 'Black iPhone',
            'category' => 'electronics',
            'description' => 'A black iPhone with a cracked screen.',
            'location' => 'Near the main gate',
            'lostAt' => now()->subDay()->toIso8601String(),
            'photo' => $malicious,
        ])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->actingAs($this->student)->post('/api/found-items', [
            'title' => 'Black iPhone',
            'category' => 'electronics',
            'description' => 'A black iPhone with a cracked screen.',
            'location' => 'Near the main gate',
            'foundAt' => now()->subDay()->toIso8601String(),
            'photo' => UploadedFile::fake()->createWithContent('photo.png', '<?php echo "pwned"; ?>'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('item_images', 0);
    }

    // =================================================================
    // 11. Error responses do not leak internals
    // =================================================================

    public function test_error_responses_do_not_include_stack_traces_or_file_paths(): void
    {
        $responses = [
            $this->getJson('/api/user'),                                   // 401
            $this->actingAs($this->student)->getJson('/api/admin/users'),   // 403
            $this->actingAs($this->student)->getJson('/api/lost-items/99999'), // 404
        ];

        foreach ($responses as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString('trace', $body);
            $this->assertStringNotContainsString('vendor\\laravel', $body);
            $this->assertStringNotContainsString('C:\\Users', $body);
            $this->assertArrayHasKey('message', $response->json());
        }
    }
}
