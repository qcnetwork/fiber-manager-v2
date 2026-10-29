<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Odp;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Odp $odp;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->area = Area::factory()->create();
        $this->odp = Odp::factory()->for($this->area)->create();
    }

    private function user(Role $role = Role::Teknisi): User
    {
        return User::factory()->create(['area_id' => $this->area->id, 'role' => $role]);
    }

    private function upload(?UploadedFile $file = null)
    {
        return $this->post('/photos', [
            'photoable_type' => 'odp',
            'photoable_id' => $this->odp->id,
            'photo' => $file ?? UploadedFile::fake()->image('tiang.jpg', 4000, 3000),
            'caption' => 'Tampak depan',
        ]);
    }

    public function test_upload_is_resized_and_gets_a_thumbnail()
    {
        $this->actingAs($this->user())->upload()->assertSessionHasNoErrors();

        $photo = $this->odp->photos()->sole();
        $this->assertSame([1600, 1200], [$photo->width, $photo->height]);
        $this->assertSame('Tampak depan', $photo->caption);
        Storage::disk('public')->assertExists([$photo->path, $photo->thumb_path]);

        [$thumbW] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
        $this->assertSame(400, $thumbW);

        $this->getJson("/odps/{$this->odp->id}")->assertJsonPath('odp.photos.0.caption', 'Tampak depan');
    }

    public function test_non_images_are_rejected()
    {
        $this->actingAs($this->user())
            ->upload(UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf'))
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, Photo::count());
    }

    public function test_viewer_cannot_upload_and_other_areas_are_hidden()
    {
        $this->actingAs($this->user(Role::Viewer))->upload()->assertForbidden();

        $outsider = User::factory()->create(['area_id' => Area::factory()->create()->id, 'role' => Role::Teknisi]);
        $this->actingAs($outsider)->upload()->assertNotFound();
    }

    public function test_uploader_or_admin_may_delete_and_files_are_removed()
    {
        $uploader = $this->user();
        $this->actingAs($uploader)->upload();
        $photo = Photo::sole();

        $this->actingAs($this->user())->delete("/photos/{$photo->id}")->assertForbidden();

        $this->actingAs($uploader)->delete("/photos/{$photo->id}")->assertRedirect();
        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing([$photo->path, $photo->thumb_path]);
    }

    public function test_deleting_the_odp_removes_its_photos()
    {
        $this->actingAs($this->user(Role::AdminArea))->upload();
        $photo = Photo::sole();

        $this->delete("/odps/{$this->odp->id}")->assertRedirect();

        $this->assertSame(0, Photo::count());
        Storage::disk('public')->assertMissing($photo->path);
    }
}
