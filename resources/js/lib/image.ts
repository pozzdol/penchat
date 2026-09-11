/**
 * Shrink a picked image before it is uploaded, to a stated ceiling.
 *
 * This is not an optimisation, it is what makes the feature work on a phone.
 * `upload_max_filesize` is 2MB on the server and a photo off a modern camera
 * is three to six — so the most ordinary thing a person can do, take a selfie
 * and choose it, would otherwise fail with PHP handing the request an empty
 * `$_FILES` and no explanation attached. Sending megabytes over mobile data to
 * produce a 256px square would be the wrong trade even if it fit.
 *
 * It is **not** a security measure and must never be read as one. Anything
 * here is trivially bypassed by posting to the endpoint directly; the server
 * decodes and re-encodes every upload on the assumption that none of this ran
 * (`App\Support\Avatar`).
 */

/**
 * The ceiling this function exists to enforce. Half the server's limit on
 * purpose: the gap is headroom for the one browser that can only produce PNG,
 * so a fallback landing at 1.2MB still arrives instead of being refused at a
 * boundary the person cannot see.
 *
 * `tests/Feature/ProfilePhotoTest.php` asserts the two still agree.
 */
const MAX_BYTES = 1024 * 1024;

/**
 * Comfortably above the 256px the server keeps, so its own resample still has
 * pixels to work with rather than upscaling ours. Larger would only be bytes
 * the server throws away.
 */
const MAX_SIDE = 512;

/**
 * Tried in order, first result under the ceiling wins.
 *
 * Quality moves before size does, because a softer 512px square still reads as
 * a face at 40px while a sharp 256px one has already thrown away the pixels
 * the server wanted. In practice the first attempt lands around 40KB and
 * nothing past it ever runs — the steps exist for the pathological image, not
 * the normal one.
 */
const ATTEMPTS: { side: number; quality: number }[] = [
    { side: MAX_SIDE, quality: 0.85 },
    { side: MAX_SIDE, quality: 0.6 },
    { side: 384, quality: 0.6 },
    { side: 256, quality: 0.5 },
];

/** Alpha-carrying, in preference order. Losing transparency here would lose
 *  it for good — the server never sees the original. */
const FORMATS = ['image/webp', 'image/png'] as const;

function encode(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

function draw(bitmap: ImageBitmap, side: number): HTMLCanvasElement | null {
    const scale = Math.min(1, side / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));

    const context = canvas.getContext('2d');
    if (!context) return null;

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    return canvas;
}

/**
 * The smallest file this browser can make of that image, at most `MAX_BYTES`.
 *
 * Returns the original only when it is already inside both limits, or when
 * every path out of here failed — a browser without `createImageBitmap`, a
 * canvas that will not encode, an image it cannot decode. Each of those hands
 * the untouched file back rather than throwing, so the upload either succeeds
 * on its own merits or is refused by the server with a message written for a
 * person. Both beat a picker that silently does nothing.
 */
export async function downscale(file: File): Promise<File> {
    if (typeof createImageBitmap !== 'function') return file;

    let bitmap: ImageBitmap;

    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    try {
        /* Both conditions, and the byte one is the whole point of this
           function. Checking dimensions alone let a 400px PNG weighing three
           megabytes through untouched — small on screen, far too big to post,
           and refused at a boundary with nothing on screen to explain it. */
        if (file.size <= MAX_BYTES && Math.max(bitmap.width, bitmap.height) <= MAX_SIDE) {
            return file;
        }

        let smallest: File | null = null;

        for (const { side, quality } of ATTEMPTS) {
            const canvas = draw(bitmap, side);
            if (!canvas) break;

            for (const type of FORMATS) {
                const blob = await encode(canvas, type, quality);

                // A browser that cannot encode the format hands back a PNG
                // under the requested name, or null. Checking the type it
                // actually produced is what stops us mislabelling the bytes.
                if (!blob || blob.type !== type) continue;

                const candidate = new File([blob], 'photo', { type });

                if (candidate.size <= MAX_BYTES) return candidate;

                if (!smallest || candidate.size < smallest.size) smallest = candidate;
            }
        }

        /* Nothing fitted. Send the smallest we managed rather than the
           original — it is the better of two files that are both too big, and
           the server still has the final say with a message a person can act
           on. Reaching here at all means a PNG-only browser and an image that
           resists compression; lossy encoding never gets close. */
        return smallest ?? file;
    } catch {
        return file;
    } finally {
        bitmap.close();
    }
}
