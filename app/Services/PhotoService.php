<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\User;
use GdImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores asset photos as re-encoded JPEGs plus a thumbnail.
 *
 * The browser already shrinks photos and applies EXIF rotation before uploading; re-encoding here
 * caps the size for clients that skipped that step and strips any remaining metadata (e.g. GPS).
 */
class PhotoService
{
    public const MAX_SIDE = 1600;

    public const THUMB_SIDE = 400;

    /** Largest image decoded, in pixels: a tiny PNG can claim huge dimensions and exhaust memory when decoded. */
    public const MAX_PIXELS = 40_000_000;

    public function store(Model $owner, UploadedFile $file, ?string $caption, ?User $user): Photo
    {
        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions && $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['photo' => 'Resolusi gambar terlalu besar (maksimal 40 megapiksel).']);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages(['photo' => 'File gambar tidak bisa dibaca. Gunakan JPG, PNG, atau WebP.']);
        }

        $dir = sprintf('photos/%s/%d', $owner->getMorphClass(), $owner->getKey());
        $name = Str::uuid()->toString();

        $full = $this->fit($image, self::MAX_SIDE);
        $thumb = $this->fit($image, self::THUMB_SIDE);

        $path = "{$dir}/{$name}.jpg";
        $thumbPath = "{$dir}/{$name}_thumb.jpg";
        $size = $this->saveJpeg($full, $path, 82);
        $this->saveJpeg($thumb, $thumbPath, 75);

        return $owner->photos()->create([
            'user_id' => $user?->id,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'caption' => $caption,
            'width' => imagesx($full),
            'height' => imagesy($full),
            'size' => $size,
        ]);
    }

    /**
     * Scales down so the longer side is at most $max pixels; never scales up.
     */
    private function fit(GdImage $image, int $max): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $max / max($w, $h));
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($newW, $newH);
        // White background so transparent PNGs don't turn black as JPEG.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);

        return $canvas;
    }

    private function saveJpeg(GdImage $image, string $path, int $quality): int
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $data = (string) ob_get_clean();

        Storage::disk(Photo::DISK)->put($path, $data);

        return strlen($data);
    }
}
