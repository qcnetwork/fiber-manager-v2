<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Services\PhotoService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhotoController extends Controller
{
    public function store(Request $request, PhotoService $photos): RedirectResponse
    {
        abort_unless($request->user()->canEditNetwork(), 403);

        $data = $request->validate([
            'photoable_type' => ['required', Rule::in(['odc', 'odp'])],
            'photoable_id' => ['required', 'integer'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photo.mimes' => 'Format foto harus JPG, PNG, atau WebP.',
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ]);

        // Resolved through the area scope, so assets in other areas are a 404.
        $owner = Relation::getMorphedModel($data['photoable_type'])::findOrFail($data['photoable_id']);

        $photos->store($owner, $request->file('photo'), $data['caption'] ?? null, $request->user());

        return back();
    }

    public function destroy(Request $request, Photo $photo): RedirectResponse
    {
        abort_if($photo->photoable === null, 404);

        $user = $request->user();
        abort_unless($user->canDeleteNetwork() || ($user->canEditNetwork() && $photo->user_id === $user->id), 403);

        $photo->delete();

        return back();
    }
}
