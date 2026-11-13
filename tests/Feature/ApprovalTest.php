<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\ChangeRequest;
use App\Models\Odp;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private User $teknisi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->teknisi = User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Teknisi]);
        $this->admin = User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]);
    }

    private function odpPayload(array $overrides = []): array
    {
        return ['code' => 'ODP-REQ-01', 'lat' => -6.2, 'lng' => 106.8, 'capacity' => 8, 'status' => 'aktif', ...$overrides];
    }

    public function test_a_technician_adding_an_odp_sends_a_request_instead()
    {
        $this->actingAs($this->teknisi)
            ->post('/odps', $this->odpPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('notice');

        $this->assertDatabaseMissing('odps', ['code' => 'ODP-REQ-01']);
        $this->assertSame(0, ActivityLog::count());

        $cr = ChangeRequest::sole();
        $this->assertSame(ChangeRequest::PENDING, $cr->status);
        $this->assertSame('created', $cr->action);
        $this->assertSame('odp', $cr->subject_type);
        $this->assertNull($cr->subject_id);
        $this->assertSame($this->area->id, $cr->area_id);
        $this->assertSame('ODP-REQ-01', $cr->subject_label);
        $this->assertSame('ODP-REQ-01', $cr->preview[0]['changes']['code'][1]);
    }

    public function test_validation_errors_go_back_to_the_technician()
    {
        $this->actingAs($this->teknisi)
            ->post('/odps', $this->odpPayload(['lat' => 999]))
            ->assertSessionHasErrors('lat');

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_approving_creates_the_odp_with_its_ports()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $cr = ChangeRequest::sole();

        $this->actingAs($this->admin)
            ->post("/approvals/{$cr->id}/approve")
            ->assertSessionHas('notice');

        $odp = Odp::firstWhere('code', 'ODP-REQ-01');
        $this->assertNotNull($odp);
        $this->assertSame($this->area->id, $odp->area_id);
        $this->assertSame(8, $odp->ports()->count());

        $cr->refresh();
        $this->assertSame(ChangeRequest::APPROVED, $cr->status);
        $this->assertSame($this->admin->id, $cr->reviewer_id);

        $log = ActivityLog::where('subject_type', 'odp')->sole();
        $this->assertStringContainsString("Request #{$cr->id}", $log->changes['request'][1]);
    }

    public function test_a_super_admin_can_approve_a_request_from_a_technician_with_an_area()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $superAdmin = User::factory()->create(['area_id' => null, 'role' => Role::SuperAdmin]);

        $this->actingAs($superAdmin)->post('/approvals/'.ChangeRequest::sole()->id.'/approve')->assertSessionHas('notice');

        $this->assertSame($this->area->id, Odp::firstWhere('code', 'ODP-REQ-01')->area_id);
    }

    public function test_a_request_that_no_longer_validates_stays_pending()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        Odp::factory()->for($this->area)->create(['code' => 'ODP-REQ-01']); // someone took the code meanwhile

        $cr = ChangeRequest::sole();
        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/approve")->assertSessionHas('noticeError');

        $this->assertSame(ChangeRequest::PENDING, $cr->fresh()->status);
        $this->assertSame(1, Odp::where('code', 'ODP-REQ-01')->count());
    }

    public function test_edits_go_through_a_request_and_show_the_changes()
    {
        $odp = Odp::factory()->for($this->area)->create(['name' => 'Lama', 'capacity' => 8]);
        $payload = ['code' => $odp->code, 'name' => 'Baru', 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => 8, 'status' => 'aktif'];

        $this->actingAs($this->teknisi)->put("/odps/{$odp->id}", $payload)->assertSessionHas('notice');
        $this->assertSame('Lama', $odp->fresh()->name);

        $cr = ChangeRequest::sole();
        $this->assertSame('updated', $cr->action);
        $this->assertSame($odp->id, $cr->subject_id);
        $this->assertSame(['Lama', 'Baru'], $cr->preview[0]['changes']['name']);

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/approve");
        $this->assertSame('Baru', $odp->fresh()->name);
    }

    public function test_a_change_made_after_the_request_is_flagged_as_a_conflict()
    {
        $odp = Odp::factory()->for($this->area)->create(['name' => 'Lama']);
        $payload = ['code' => $odp->code, 'name' => 'Dari teknisi', 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => $odp->capacity, 'status' => 'aktif'];
        $this->actingAs($this->teknisi)->put("/odps/{$odp->id}", $payload);
        $cr = ChangeRequest::sole();
        $this->assertNull($cr->conflict());

        $this->travel(5)->minutes();
        $odp->update(['name' => 'Diubah admin']);

        $this->assertNotNull($cr->fresh()->conflict());
    }

    public function test_a_technician_may_ask_to_delete()
    {
        $odp = Odp::factory()->for($this->area)->create();

        $this->actingAs($this->teknisi)->delete("/odps/{$odp->id}")->assertSessionHas('notice');
        $this->assertModelExists($odp);

        $cr = ChangeRequest::sole();
        $this->assertSame('deleted', $cr->action);
        $this->assertSame($odp->code, $cr->preview[0]['changes']['code'][0]);

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/approve");
        $this->assertModelMissing($odp);
    }

    public function test_rejecting_keeps_the_data_and_notifies_the_technician()
    {
        $odp = Odp::factory()->for($this->area)->create();
        $this->actingAs($this->teknisi)->delete("/odps/{$odp->id}");
        $cr = ChangeRequest::sole();

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/reject", ['note' => 'Masih dipakai'])->assertSessionHas('notice');

        $this->assertModelExists($odp);
        $this->assertSame(ChangeRequest::REJECTED, $cr->fresh()->status);
        $this->assertSame('Masih dipakai', $cr->fresh()->review_note);

        // The technician gets a badge until they open the approval page.
        $this->actingAs($this->teknisi)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.decided_unseen', 1));
        $this->get('/approvals')->assertOk();
        $this->get('/map')->assertInertia(fn ($page) => $page->where('approvals.decided_unseen', 0));
    }

    public function test_approvers_see_the_pending_count()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload(['code' => 'ODP-REQ-02']));

        $this->actingAs($this->admin)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.pending', 2));
    }

    public function test_area_admins_only_decide_requests_of_their_own_area()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $cr = ChangeRequest::sole();
        $otherAdmin = User::factory()->create(['area_id' => Area::factory()->create()->id, 'role' => Role::AdminArea]);

        $this->actingAs($otherAdmin)->post("/approvals/{$cr->id}/approve")->assertNotFound();
        $this->actingAs($otherAdmin)->post("/approvals/{$cr->id}/reject")->assertNotFound();
        $this->actingAs($otherAdmin)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.pending', 0));
        $this->assertSame(ChangeRequest::PENDING, $cr->fresh()->status);
    }

    public function test_technicians_cannot_approve_and_may_cancel_their_own_requests()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $cr = ChangeRequest::sole();

        $this->actingAs($this->teknisi)->post("/approvals/{$cr->id}/approve")->assertNotFound();
        $other = User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Teknisi]);
        $this->actingAs($other)->post("/approvals/{$cr->id}/cancel")->assertNotFound();

        $this->actingAs($this->teknisi)->post("/approvals/{$cr->id}/cancel")->assertSessionHas('notice');
        $this->assertSame(ChangeRequest::CANCELLED, $cr->fresh()->status);
    }

    public function test_admins_still_save_straight_away()
    {
        $this->actingAs($this->admin)->post('/odps', $this->odpPayload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('odps', ['code' => 'ODP-REQ-01']);
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_an_approver_can_change_a_request_before_approving_it()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $cr = ChangeRequest::sole();

        $this->actingAs($this->admin)->getJson("/approvals/{$cr->id}")
            ->assertOk()
            ->assertJsonPath('request.payload.code', 'ODP-REQ-01');

        $this->post('/odps', $this->odpPayload(['code' => 'ODP-REQ-99', 'change_request_id' => $cr->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('notice');

        $this->assertDatabaseHas('odps', ['code' => 'ODP-REQ-99']);
        $this->assertDatabaseMissing('odps', ['code' => 'ODP-REQ-01']);
        $this->assertSame(ChangeRequest::APPROVED, $cr->fresh()->status);
    }

    public function test_photos_wait_on_disk_until_approved()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create();

        $this->actingAs($this->teknisi)->post('/photos', [
            'photoable_type' => 'odp',
            'photoable_id' => $odp->id,
            'photo' => UploadedFile::fake()->image('tiang.jpg', 800, 600),
            'caption' => 'Tiang depan',
        ])->assertSessionHasNoErrors()->assertSessionHas('notice');

        $this->assertSame(0, Photo::count());
        $cr = ChangeRequest::sole();
        $pending = $cr->files[0]['path'];
        Storage::disk(Photo::DISK)->assertExists($pending);
        $this->actingAs($this->admin)->get("/approvals/{$cr->id}/files/0")->assertOk();

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/approve")->assertSessionHas('notice');

        $photo = Photo::sole();
        $this->assertSame($this->teknisi->id, $photo->user_id);
        $this->assertSame('Tiang depan', $photo->caption);
        Storage::disk(Photo::DISK)->assertExists($photo->path);
        Storage::disk(Photo::DISK)->assertMissing($pending);
    }

    public function test_a_rejected_photo_is_removed_from_disk()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create();

        $this->actingAs($this->teknisi)->post('/photos', [
            'photoable_type' => 'odp',
            'photoable_id' => $odp->id,
            'photo' => UploadedFile::fake()->image('tiang.jpg', 800, 600),
        ]);
        $cr = ChangeRequest::sole();

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/reject");

        Storage::disk(Photo::DISK)->assertMissing($cr->files[0]['path']);
        $this->assertSame(0, Photo::count());
    }

    public function test_technicians_cannot_ask_to_delete_photos_of_others()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create();
        $photo = $odp->photos()->create(['user_id' => $this->admin->id, 'path' => 'a.jpg', 'thumb_path' => 'a_t.jpg', 'width' => 10, 'height' => 10, 'size' => 100]);

        $this->actingAs($this->teknisi)->delete("/photos/{$photo->id}")->assertForbidden();
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_cable_and_a_core_status_go_through_requests()
    {
        $this->actingAs($this->teknisi)->post('/cables', [
            'type' => 'distribusi', 'cores' => 12, 'tube_size' => 12, 'status' => 'aktif', 'code' => 'KBL-REQ',
            'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ])->assertSessionHasNoErrors()->assertSessionHas('notice');
        $this->assertSame(0, Cable::count());

        $this->actingAs($this->admin)->post('/approvals/'.ChangeRequest::sole()->id.'/approve')->assertSessionHas('notice');
        $cable = Cable::sole();
        $this->assertSame(12, $cable->coreRows()->count());

        $core = $cable->coreRows()->where('number', 2)->sole();
        $this->actingAs($this->teknisi)->patch("/cable-cores/{$core->id}", ['status' => 'terpakai', 'notes' => 'Ke ODP-07'])
            ->assertSessionHas('notice');
        $this->assertSame('kosong', $core->fresh()->status->value);

        $cr = ChangeRequest::pending()->sole();
        $this->assertSame('KBL-REQ core 2', $cr->subject_label);
        $this->assertSame(['kosong', 'terpakai'], $cr->preview[0]['changes']['status']);

        $this->actingAs($this->admin)->post("/approvals/{$cr->id}/approve");
        $this->assertSame('terpakai', $core->fresh()->status->value);
    }

    public function test_the_approval_page_lists_requests()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());

        $this->actingAs($this->admin)->get('/approvals')->assertInertia(fn ($page) => $page
            ->component('Approvals')
            ->has('requests.data', 1)
            ->where('requests.data.0.subject_label', 'ODP-REQ-01')
            ->where('requests.data.0.editable', true));

        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Viewer]))
            ->get('/approvals')->assertForbidden();
    }
}
