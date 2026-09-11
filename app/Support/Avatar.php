<?php

namespace App\Support;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Profile photos: the first bytes this app ever accepts from a person, and so
 * the most dangerous thing in it.
 *
 * Every rule below assumes the upload is hostile and the validation layer was
 * fooled. A mime type is a claim the uploader makes; an extension is a
 * suggestion. The only statement here that cannot be argued with is that GD
 * decoded the bytes into pixels — so that is the check the rest hangs off, and
 * nothing reaches the disk that this process did not encode itself.
 *
 * That re-encode has a second effect worth naming: it drops every EXIF block,
 * which is where a phone writes the GPS coordinates of the place the photo was
 * taken. Publishing a profile picture should not publish someone's address.
 *
 * WebP, one output format and no branching: GD has it here, it carries alpha
 * so a transparent logo does not get flattened onto white in the dark theme,
 * and a 256px square lands around 10KB.
 */
final class Avatar
{
    /** One size. A set of sizes at five users is a CDN nobody asked for. */
    public const SIZE = 256;

    private const DIRECTORY = 'avatars';

    private const QUALITY = 82;

    /**
     * Refused before a single pixel is allocated.
     *
     * GD reserves width × height × 4 bytes the moment it decodes, and
     * `memory_limit` is unlimited on this host — so a 40000×40000 PNG that
     * compresses to 50KB would take the whole box down rather than fail a
     * request. The header is read first precisely so that never happens.
     */
    private const MAX_SIDE = 10_000;

    private const MAX_PIXELS = 40_000_000;

    /**
     * Swap in a new photo and drop the old one.
     *
     * The new file is written before the row moves, and the old file deleted
     * only after — an ordering that can leak one orphaned file if the process
     * dies mid-way, which is a great deal better than a row pointing at a file
     * that is no longer there.
     *
     * @throws ValidationException
     */
    public static function replace(User $user, UploadedFile $file): void
    {
        $previous = $user->avatar_path;

        $user->update(['avatar_path' => self::store($file)]);

        self::forget($previous);
    }

    public static function remove(User $user): void
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return;
        }

        $user->update(['avatar_path' => null]);

        self::forget($previous);
    }

    /**
     * Decode, crop, resize, re-encode, write. The path it returns is a name
     * this process invented — the uploader's filename is never read.
     *
     * @throws ValidationException
     */
    private static function store(UploadedFile $file): string
    {
        $source = self::decode($file);

        try {
            $square = self::square($source);

            try {
                ob_start();
                imagewebp($square, null, self::QUALITY);
                $bytes = (string) ob_get_clean();
            } finally {
                imagedestroy($square);
            }
        } finally {
            imagedestroy($source);
        }

        // A fresh ULID every time, so no cache anywhere can be holding
        // different bytes under this path — the reason not to key the file on
        // the user id.
        $path = self::DIRECTORY.'/'.Str::ulid().'.webp';

        /*
         * A failed write has two shapes here, and neither used to be noticed.
         *
         * The `public` disk is configured `'throw' => false`, so a write into
         * a directory the web user cannot write to returns **false** and says
         * nothing at all — the row was then updated to point at a file that
         * had never existed, and the avatar silently never loaded while Change
         * and Remove sat there insisting one did. That is how this was found.
         *
         * But `FilesystemAdapter::put()` only catches `UnableToWriteFile` and
         * `UnableToSetVisibility`. A directory that cannot be *created*
         * escapes as `UnableToCreateDirectory` whatever `throw` is set to, so
         * the flag is not the whole story and a bare return check is not
         * enough. Both are caught, both refuse, and neither writes a path to
         * a file that is not there.
         *
         * The realistic cause is ownership: PHP-FPM runs as www-data, and a
         * directory created by anything else — a CLI command run as root, a
         * deploy step — is not writable by it. Logged with the root so the
         * next person gets the answer rather than the symptom.
         */
        try {
            $written = Storage::disk('public')->put($path, $bytes);
        } catch (Throwable $e) {
            Log::error('Avatar could not be written.', [
                'path' => $path,
                'disk_root' => config('filesystems.disks.public.root'),
                'exception' => $e,
            ]);

            self::refuse('That photo could not be saved. Try again in a moment.');
        }

        if ($written === false) {
            Log::error('Avatar write was refused by the disk.', [
                'path' => $path,
                'disk_root' => config('filesystems.disks.public.root'),
            ]);

            self::refuse('That photo could not be saved. Try again in a moment.');
        }

        return $path;
    }

    /**
     * Bytes to pixels, or a refusal.
     *
     * `imagecreatefromstring` is the whole security boundary. It cannot decode
     * SVG under any circumstances, which is what makes the scripted-image
     * problem structurally impossible here rather than a mime check somebody
     * could get wrong later.
     *
     * @throws ValidationException
     */
    private static function decode(UploadedFile $file): GdImage
    {
        $info = @getimagesize($file->getPathname());

        if ($info === false) {
            self::refuse('That file is not an image we can read.');
        }

        [$width, $height] = $info;

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            self::refuse('That image is too large to process. Try one under '.self::MAX_SIDE.' pixels a side.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));

        if (! $image instanceof GdImage) {
            self::refuse('That file is not an image we can read.');
        }

        return $image;
    }

    /**
     * Centre-cropped to a square, then down to one size, so every avatar in a
     * list is the same shape and no layout has to cope with a panorama.
     */
    private static function square(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);

        // Alpha kept rather than composited: WebP carries it, and flattening a
        // transparent logo onto white would look like a bug against the dark
        // palette.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            self::SIZE,
            self::SIZE,
            $side,
            $side,
        );

        return $target;
    }

    /**
     * Delete a file this app wrote, and only that.
     *
     * The prefix check is belt and braces — `avatar_path` is always a name
     * generated above — but it is the difference between a bug in some future
     * caller being a bug and it being a way to delete an arbitrary file.
     */
    private static function forget(?string $path): void
    {
        if ($path === null || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * @return never
     *
     * @throws ValidationException
     */
    private static function refuse(string $message)
    {
        throw ValidationException::withMessages(['photo' => $message]);
    }
}
