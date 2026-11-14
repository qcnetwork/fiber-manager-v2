<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Photo;
use App\Services\PhotoService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function store(Request $request, PhotoService $photos): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate([
            'photoable_type' => ['required', Rule::in(['pop', 'odc', 'odp', 'joint', 'customer'])],
            'photoable_id' => ['required', 'integer'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photo.mimes' => 'Format foto harus JPG, PNG, atau WebP.',
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ]);

        // Resolved through the area scope, so assets in other areas are a 404.
        $owner = Relation::getMorphedModel($data['photoable_type'])::findOrFail($data['photoable_id']);
        abort_if($owner instanceof Area && ! $request->user()->canAccessArea($owner->id), 404);

        $photos->store($owner, $request->file('photo'), $data['caption'] ?? null, $request->user());

        return back();
    }

    /**
     * The photo file (or its thumbnail), only for users who may see what it belongs to.
     */
    public function file(Request $request, Photo $photo): StreamedResponse
    {
        // Resolved through the area scope: photos of assets in other areas are a 404.
        $owner = $photo->photoable;
        abort_if($owner === null || ($owner instanceof Area && ! $request->user()->canAccessArea($owner->id)), 404);

        $path = $request->boolean('thumb') ? $photo->thumb_path : $photo->path;
        abort_unless(Storage::disk(Photo::DISK)->exists($path), 404);

        return Storage::disk(Photo::DISK)->response($path, null, [
            // Photos never change once uploaded; keep them in the browser but not in shared caches.
            'Cache-Control' => 'private, max-age=604800, immutable',
        ]);
    }

    /**
     * Changes a photo's caption and/or puts a new image in its place.
     */
    public function update(Request $request, Photo $photo, PhotoService $photos): RedirectResponse
    {
        $this->ownerOrFail($request, $photo);
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'photo.mimes' => 'Format foto harus JPG, PNG, atau WebP.',
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ]);

        $changes = [];
        if ($request->has('caption') && ($data['caption'] ?? null) !== $photo->caption) {
            $changes['caption'] = [$photo->caption, $data['caption'] ?? null];
            $photo->caption = $data['caption'] ?? null;
        }
        if ($file = $request->file('photo')) {
            $photos->replace($photo, $file);
            $changes['photo'] = ['Foto lama', 'Diganti foto baru'];
        }

        // One log entry for both; the file columns themselves are not logged.
        if ($changes) {
            $photo->saveQuietly();
            $photo->logActivity('updated', $changes);
        }

        return back();
    }

    public function destroy(Request $request, Photo $photo): RedirectResponse
    {
        $this->ownerOrFail($request, $photo);

        // Technicians' deletes are drafts an admin approves, so they may ask to delete anyone's photo.
        $user = $request->user();
        abort_unless($user->canDeleteNetwork() || ($user->canEditNetwork() && ($photo->user_id === $user->id || $user->needsApproval())), 403);

        $photo->delete();

        return back();
    }

    /**
     * Resolved through the area scope: photos of assets in other areas are a 404.
     */
    private function ownerOrFail(Request $request, Photo $photo): void
    {
        $owner = $photo->photoable;
        abort_if($owner === null || ($owner instanceof Area && ! $request->user()->canAccessArea($owner->id)), 404);
    }
}
