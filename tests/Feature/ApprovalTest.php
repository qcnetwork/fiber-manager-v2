<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\ChangeBatch;
use App\Models\ChangeRequest;
use App\Models\Odc;
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

    private function odcPayload(array $overrides = []): array
    {
        return ['code' => 'ODC-NEW', 'lat' => -6.2, 'lng' => 106.79, 'capacity' => 4, 'status' => 'aktif', ...$overrides];
    }

    /** Sends the technician's drafts and returns the batch. */
    private function submit(?string $note = null): ChangeBatch
    {
        $this->actingAs($this->teknisi)->post('/drafts/submit', ['note' => $note])->assertSessionHas('notice');

        return ChangeBatch::latest('id')->firstOrFail();
    }

    public function test_a_technicians_change_becomes_a_draft()
    {
        $this->actingAs($this->teknisi)
            ->post('/odps', $this->odpPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('notice');

        $this->assertDatabaseMissing('odps', ['code' => 'ODP-REQ-01']);
        $this->assertSame(0, ActivityLog::count());

        $draft = ChangeRequest::sole();
        $this->assertSame(ChangeRequest::DRAFT, $draft->status);
        $this->assertNull($draft->batch_id);
        $this->assertSame('created', $draft->action);
        $this->assertSame('odp', $draft->subject_type);
        $this->assertSame($this->area->id, $draft->area_id);
        $this->assertSame('ODP-REQ-01', $draft->preview[0]['changes']['code'][1]);

        // Shown on the technician's map, and counted.
        $this->getJson('/drafts')->assertJsonPath('items.0.token', -$draft->id)->assertJsonPath('items.0.lat', -6.2);
        $this->get('/map')->assertInertia(fn ($page) => $page->where('approvals.drafts', 1));
        // Approvers don't see drafts.
        $this->actingAs($this->admin)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.pending', 0));
    }

    public function test_validation_errors_go_back_to_the_technician()
    {
        $this->actingAs($this->teknisi)
            ->post('/odps', $this->odpPayload(['lat' => 999]))
            ->assertSessionHasErrors('lat');

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_drafts_build_on_each_other_and_are_applied_together()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odcs', $this->odcPayload())->assertSessionHasNoErrors();
        $odcToken = -ChangeRequest::sole()->id;

        // An ODP on a port of the draft ODC, then a second one on the same port is refused.
        $this->post('/odps', $this->odpPayload(['odc_id' => $odcToken, 'odc_port' => 2]))->assertSessionHasNoErrors();
        $odpToken = -ChangeRequest::latest('id')->first()->id;
        $this->post('/odps', $this->odpPayload(['code' => 'ODP-REQ-02', 'odc_id' => $odcToken, 'odc_port' => 2]))->assertSessionHasErrors('odc_port');

        // A cable from the draft ODC to the draft ODP.
        $this->post('/cables', [
            'code' => 'KBL-NEW', 'type' => 'distribusi', 'cores' => 12, 'tube_size' => 12, 'status' => 'aktif',
            'path' => [[-6.2, 106.79], [-6.2, 106.8]],
            'from_type' => 'odc', 'from_id' => $odcToken, 'to_type' => 'odp', 'to_id' => $odpToken,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, ChangeRequest::count());
        $this->assertSame(0, Odc::count() + Odp::count() + Cable::count());

        $batch = $this->submit('Jalur baru');
        $this->assertSame(ChangeBatch::PENDING, $batch->status);
        $this->assertSame('Jalur baru', $batch->note);
        $this->assertSame(3, $batch->items()->where('status', ChangeRequest::PENDING)->count());
        $this->actingAs($this->admin)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.pending', 1));

        $this->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');

        $odc = Odc::sole();
        $odp = Odp::sole();
        $cable = Cable::sole();
        $this->assertSame([$odc->id, 2], [$odp->odc_id, $odp->odc_port]);
        $this->assertSame(['odc', $odc->id, 'odp', $odp->id], [$cable->from_type, $cable->from_id, $cable->to_type, $cable->to_id]);
        $this->assertSame(ChangeBatch::APPROVED, $batch->fresh()->status);
        $this->assertSame(3, ChangeRequest::where('status', ChangeRequest::APPROVED)->count());

        $log = ActivityLog::where('subject_type', 'odc')->sole();
        $this->assertStringContainsString("Paket #{$batch->id}", $log->changes['request'][1]);
    }

    public function test_a_batch_that_no_longer_applies_saves_nothing()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odcs', $this->odcPayload());
        $this->post('/odps', $this->odpPayload());
        $batch = $this->submit();

        Odp::factory()->for($this->area)->create(['code' => 'ODP-REQ-01']); // someone took the code meanwhile

        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('noticeError');

        $this->assertSame(0, Odc::count());
        $this->assertSame(ChangeBatch::PENDING, $batch->fresh()->status);
    }

    public function test_drafts_that_others_build_on_cannot_be_removed_alone()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odcs', $this->odcPayload());
        $odcDraft = ChangeRequest::sole();
        $this->post('/odps', $this->odpPayload(['odc_id' => $odcDraft->token(), 'odc_port' => 1]));

        $this->delete("/drafts/{$odcDraft->id}")->assertSessionHas('noticeError');
        $this->assertModelExists($odcDraft);

        $this->delete('/drafts')->assertSessionHas('notice');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_technician_edits_their_own_draft()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odps', $this->odpPayload());
        $draft = ChangeRequest::sole();

        $this->post('/odps', $this->odpPayload(['code' => 'ODP-REQ-09', 'draft_id' => $draft->id]))->assertSessionHas('notice');

        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame('ODP-REQ-09', $draft->fresh()->payload['code']);
        $this->assertSame('ODP-REQ-09', $draft->fresh()->subject_label);
    }

    public function test_changing_a_draft_may_not_break_drafts_built_on_it()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odcs', $this->odcPayload(['capacity' => 4]));
        $odcDraft = ChangeRequest::sole();
        $this->post('/odps', $this->odpPayload(['odc_id' => $odcDraft->token(), 'odc_port' => 4]));

        // Port 4 would no longer exist.
        $this->post('/odcs', $this->odcPayload(['capacity' => 2, 'draft_id' => $odcDraft->id]))->assertSessionHas('noticeError');
        $this->assertSame(4, $odcDraft->fresh()->payload['capacity']);
    }

    public function test_edits_and_deletes_of_existing_data_wait_for_the_batch()
    {
        $odp = Odp::factory()->for($this->area)->create(['name' => 'Lama', 'capacity' => 8]);
        $gone = Odp::factory()->for($this->area)->create();
        $payload = ['code' => $odp->code, 'name' => 'Baru', 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => 8, 'status' => 'aktif'];

        $this->actingAs($this->teknisi);
        $this->put("/odps/{$odp->id}", $payload)->assertSessionHas('notice');
        $this->delete("/odps/{$gone->id}")->assertSessionHas('notice');
        $this->assertSame('Lama', $odp->fresh()->name);
        $this->assertModelExists($gone);

        [$edit, $delete] = ChangeRequest::orderBy('id')->get();
        $this->assertSame(['Lama', 'Baru'], $edit->preview[0]['changes']['name']);
        $this->assertSame('deleted', $delete->action);

        $batch = $this->submit();
        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve");

        $this->assertSame('Baru', $odp->fresh()->name);
        $this->assertModelMissing($gone);
    }

    public function test_a_change_made_after_the_draft_is_flagged_as_a_conflict()
    {
        $odp = Odp::factory()->for($this->area)->create(['name' => 'Lama']);
        $payload = ['code' => $odp->code, 'name' => 'Dari teknisi', 'lat' => $odp->lat, 'lng' => $odp->lng, 'capacity' => $odp->capacity, 'status' => 'aktif'];
        $this->actingAs($this->teknisi)->put("/odps/{$odp->id}", $payload);
        $draft = ChangeRequest::sole();
        $this->assertNull($draft->conflict());

        $this->travel(5)->minutes();
        $odp->update(['name' => 'Diubah admin']);

        $this->assertNotNull($draft->fresh()->conflict());
    }

    public function test_rejecting_keeps_the_data_and_notifies_the_technician()
    {
        $odp = Odp::factory()->for($this->area)->create();
        $this->actingAs($this->teknisi)->delete("/odps/{$odp->id}");
        $batch = $this->submit();

        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/reject", ['note' => 'Masih dipakai'])->assertSessionHas('notice');

        $this->assertModelExists($odp);
        $this->assertSame(ChangeBatch::REJECTED, $batch->fresh()->status);
        $this->assertSame('Masih dipakai', $batch->fresh()->review_note);
        $this->assertSame(ChangeRequest::REJECTED, ChangeRequest::sole()->status);

        // The technician gets a badge until they open the approval page.
        $this->actingAs($this->teknisi)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.decided_unseen', 1));
        $this->get('/approvals')->assertOk();
        $this->get('/map')->assertInertia(fn ($page) => $page->where('approvals.decided_unseen', 0));
    }

    public function test_area_admins_only_decide_batches_of_their_own_area()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $batch = $this->submit();
        $otherAdmin = User::factory()->create(['area_id' => Area::factory()->create()->id, 'role' => Role::AdminArea]);

        $this->actingAs($otherAdmin)->post("/approvals/{$batch->id}/approve")->assertNotFound();
        $this->actingAs($otherAdmin)->post("/approvals/{$batch->id}/reject")->assertNotFound();
        $this->actingAs($otherAdmin)->get('/map')->assertInertia(fn ($page) => $page->where('approvals.pending', 0));
        $this->assertSame(ChangeBatch::PENDING, $batch->fresh()->status);

        // A super admin decides batches of every area.
        $superAdmin = User::factory()->create(['area_id' => null, 'role' => Role::SuperAdmin]);
        $this->actingAs($superAdmin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');
        $this->assertSame($this->area->id, Odp::firstWhere('code', 'ODP-REQ-01')->area_id);
    }

    public function test_technicians_cannot_approve_and_may_cancel_their_own_batches()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $batch = $this->submit();

        $this->actingAs($this->teknisi)->post("/approvals/{$batch->id}/approve")->assertNotFound();
        $other = User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Teknisi]);
        $this->actingAs($other)->post("/approvals/{$batch->id}/cancel")->assertNotFound();
        $this->actingAs($other)->delete('/drafts/'.ChangeRequest::sole()->id)->assertNotFound();

        $this->actingAs($this->teknisi)->post("/approvals/{$batch->id}/cancel")->assertSessionHas('notice');
        $this->assertSame(ChangeBatch::CANCELLED, $batch->fresh()->status);
    }

    public function test_admins_still_save_straight_away()
    {
        $this->actingAs($this->admin)->post('/odps', $this->odpPayload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('odps', ['code' => 'ODP-REQ-01']);
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_an_approver_can_change_an_item_before_approving_the_batch()
    {
        $this->actingAs($this->teknisi);
        $this->post('/odcs', $this->odcPayload());
        $odcDraft = ChangeRequest::sole();
        $this->post('/odps', $this->odpPayload(['odc_id' => $odcDraft->token(), 'odc_port' => 1]));
        $odpItem = ChangeRequest::latest('id')->first();
        $batch = $this->submit();

        $this->actingAs($this->admin)->getJson("/approvals/items/{$odpItem->id}")
            ->assertOk()
            ->assertJsonPath('request.payload.code', 'ODP-REQ-01')
            ->assertJsonCount(2, 'overlay');

        $this->post('/odps', $this->odpPayload(['code' => 'ODP-REQ-99', 'odc_id' => $odcDraft->token(), 'odc_port' => 3, 'change_request_id' => $odpItem->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('notice');

        // Nothing applied yet: the item holds the approver's version.
        $this->assertSame(0, Odp::count());
        $this->assertSame('ODP-REQ-99', $odpItem->fresh()->payload['code']);

        $this->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');
        $this->assertSame([3, Odc::sole()->id], [Odp::sole()->odc_port, Odp::sole()->odc_id]);
        $this->assertSame('ODP-REQ-99', Odp::sole()->code);
    }

    public function test_photos_of_draft_assets_wait_on_disk_until_approved()
    {
        Storage::fake(Photo::DISK);
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $odpDraft = ChangeRequest::sole();

        $this->post('/photos', [
            'photoable_type' => 'odp',
            'photoable_id' => $odpDraft->token(),
            'photo' => UploadedFile::fake()->image('tiang.jpg', 800, 600),
            'caption' => 'Tiang depan',
        ])->assertSessionHasNoErrors()->assertSessionHas('notice');

        $this->assertSame(0, Photo::count());
        $photoDraft = ChangeRequest::latest('id')->first();
        $this->assertSame('Foto ODP-REQ-01', $photoDraft->subject_label);
        $pending = $photoDraft->files[0]['path'];
        Storage::disk(Photo::DISK)->assertExists($pending);
        $this->get("/approvals/items/{$photoDraft->id}/files/0")->assertOk();

        $batch = $this->submit();
        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');

        $photo = Photo::sole();
        $this->assertSame(Odp::sole()->id, $photo->photoable_id);
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
        $path = ChangeRequest::sole()->files[0]['path'];
        $batch = $this->submit();

        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/reject");

        Storage::disk(Photo::DISK)->assertMissing($path);
        $this->assertSame(0, Photo::count());
    }

    public function test_a_failed_batch_keeps_the_photo_files_of_what_it_deleted()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create();
        $photo = $odp->photos()->create(['user_id' => $this->admin->id, 'path' => 'a.jpg', 'thumb_path' => 'a_t.jpg', 'width' => 10, 'height' => 10, 'size' => 100]);
        Storage::disk(Photo::DISK)->put('a.jpg', 'x');

        $this->actingAs($this->teknisi);
        $this->delete("/odps/{$odp->id}");
        $this->post('/odps', $this->odpPayload());
        $batch = $this->submit();
        Odp::factory()->for($this->area)->create(['code' => 'ODP-REQ-01']);

        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('noticeError');

        $this->assertModelExists($photo);
        Storage::disk(Photo::DISK)->assertExists('a.jpg');
    }

    public function test_technicians_can_ask_to_delete_any_photo()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-FOTO']);
        $photo = $odp->photos()->create(['user_id' => $this->admin->id, 'path' => 'a.jpg', 'thumb_path' => 'a_t.jpg', 'width' => 10, 'height' => 10, 'size' => 100]);
        Storage::disk(Photo::DISK)->put('a.jpg', 'x');

        $this->actingAs($this->teknisi)->delete("/photos/{$photo->id}")->assertSessionHas('notice');
        $this->assertModelExists($photo);
        Storage::disk(Photo::DISK)->assertExists('a.jpg');

        $draft = ChangeRequest::sole();
        $this->assertSame(['deleted', 'photo', $photo->id], [$draft->action, $draft->subject_type, $draft->subject_id]);
        // Shown at the ODP it belongs to.
        $this->getJson('/drafts')->assertJsonPath('items.0.lat', (float) $odp->lat);

        $batch = $this->submit();
        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');

        $this->assertModelMissing($photo);
        Storage::disk(Photo::DISK)->assertMissing('a.jpg');
    }

    public function test_technicians_ask_to_change_a_caption_and_replace_a_photo()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-FOTO']);
        Storage::disk(Photo::DISK)->put('photos/odp/1/a.jpg', 'x');
        Storage::disk(Photo::DISK)->put('photos/odp/1/a_t.jpg', 'x');
        $photo = $odp->photos()->create([
            'user_id' => $this->admin->id, 'path' => 'photos/odp/1/a.jpg', 'thumb_path' => 'photos/odp/1/a_t.jpg',
            'caption' => 'Lama', 'width' => 10, 'height' => 10, 'size' => 100,
        ]);

        $this->actingAs($this->teknisi)->put("/photos/{$photo->id}", [
            'caption' => 'Tiang baru',
            'photo' => UploadedFile::fake()->image('baru.jpg', 800, 600),
        ])->assertSessionHasNoErrors()->assertSessionHas('notice');

        // Nothing changes until approved.
        $this->assertSame(['Lama', 'photos/odp/1/a.jpg'], [$photo->fresh()->caption, $photo->fresh()->path]);
        $draft = ChangeRequest::sole();
        $this->assertSame(['updated', 'photo', $photo->id], [$draft->action, $draft->subject_type, $draft->subject_id]);
        $this->assertSame(['Lama', 'Tiang baru'], $draft->preview[0]['changes']['caption']);
        $this->assertArrayHasKey('photo', $draft->preview[0]['changes']);
        $pending = $draft->files[0]['path'];
        Storage::disk(Photo::DISK)->assertExists($pending);

        $batch = $this->submit();
        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve")->assertSessionHas('notice');

        $photo->refresh();
        $this->assertSame('Tiang baru', $photo->caption);
        $this->assertNotSame('photos/odp/1/a.jpg', $photo->path);
        $this->assertSame(800, $photo->width);
        $this->assertSame($this->admin->id, $photo->user_id);
        Storage::disk(Photo::DISK)->assertExists([$photo->path, $photo->thumb_path]);
        Storage::disk(Photo::DISK)->assertMissing(['photos/odp/1/a.jpg', 'photos/odp/1/a_t.jpg', $pending]);

        $log = ActivityLog::where('subject_type', 'photo')->sole();
        $this->assertSame('updated', $log->action);
        $this->assertSame(['Lama', 'Tiang baru'], $log->changes['caption']);
        $this->assertArrayHasKey('photo', $log->changes);
    }

    public function test_an_unchanged_photo_edit_makes_no_draft()
    {
        $odp = Odp::factory()->for($this->area)->create();
        $photo = $odp->photos()->create(['user_id' => $this->admin->id, 'path' => 'a.jpg', 'thumb_path' => 'a_t.jpg', 'caption' => 'Sama', 'width' => 10, 'height' => 10, 'size' => 100]);

        $this->actingAs($this->teknisi)->put("/photos/{$photo->id}", ['caption' => 'Sama'])->assertSessionHas('notice', 'Tidak ada perubahan untuk disimpan.');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_admins_edit_photos_directly()
    {
        Storage::fake(Photo::DISK);
        $odp = Odp::factory()->for($this->area)->create();
        $photo = $odp->photos()->create(['user_id' => $this->teknisi->id, 'path' => 'photos/odp/1/a.jpg', 'thumb_path' => 'photos/odp/1/a_t.jpg', 'width' => 10, 'height' => 10, 'size' => 100]);

        $this->actingAs($this->admin)->put("/photos/{$photo->id}", ['caption' => 'Depan', 'photo' => UploadedFile::fake()->image('x.png', 300, 200)])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Depan', 300], [$photo->fresh()->caption, $photo->fresh()->width]);
        $this->assertSame(0, ChangeRequest::count());
    }


    public function test_a_cable_and_a_core_status_go_through_drafts()
    {
        $cable = Cable::create([
            'area_id' => $this->area->id, 'code' => 'KBL-REQ', 'type' => 'distribusi', 'cores' => 12, 'tube_size' => 12,
            'status' => 'aktif', 'path' => [[-6.2, 106.8], [-6.2, 106.801]],
        ]);
        $core = $cable->coreRows()->where('number', 2)->sole();

        $this->actingAs($this->teknisi)->patch("/cable-cores/{$core->id}", ['status' => 'terpakai', 'notes' => 'Ke ODP-07'])
            ->assertSessionHas('notice');
        $this->assertSame('kosong', $core->fresh()->status->value);

        $draft = ChangeRequest::sole();
        $this->assertSame('KBL-REQ core 2', $draft->subject_label);
        $this->assertSame(['kosong', 'terpakai'], $draft->preview[0]['changes']['status']);

        $batch = $this->submit();
        $this->actingAs($this->admin)->post("/approvals/{$batch->id}/approve");
        $this->assertSame('terpakai', $core->fresh()->status->value);
    }

    public function test_the_approval_page_lists_batches()
    {
        $this->actingAs($this->teknisi)->post('/odps', $this->odpPayload());
        $this->submit('Tolong dicek');

        $this->actingAs($this->admin)->get('/approvals')->assertInertia(fn ($page) => $page
            ->component('Approvals')
            ->has('batches.data', 1)
            ->where('batches.data.0.note', 'Tolong dicek')
            ->where('batches.data.0.items.0.subject_label', 'ODP-REQ-01')
            ->where('batches.data.0.items.0.editable', true));

        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Viewer]))
            ->get('/approvals')->assertForbidden();
    }
}
