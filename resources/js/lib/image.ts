/**
 * Shrinks a photo in the browser before upload: longest side at most `maxSide`, re-encoded as JPEG.
 *
 * Phone photos are often 3–8 MB; this brings them to a few hundred KB, which keeps uploads quick on
 * mobile data and under the server's upload limit. Drawing through createImageBitmap applies the EXIF
 * rotation, so portrait shots stay upright. If the browser can't decode the file, the original is returned
 * and the server decides.
 */
export async function compressImage(file: File, maxSide = 1600, quality = 0.82): Promise<File> {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const ctx = canvas.getContext('2d')!;
        ctx.fillStyle = '#fff'; // transparent PNG areas would otherwise turn black
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) return file;

        const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], name, { type: 'image/jpeg' });
    } catch {
        return file;
    }
}
