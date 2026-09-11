import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Choose which square of a photo becomes the avatar.
 *
 * Without this the server centre-cropped whatever arrived, and the person
 * uploading had no way to know what survived — a portrait taken in landscape
 * lost both ears, and the only way to find out was to look afterwards. The
 * frame here shows exactly the pixels that will be kept, at the moment of
 * choosing rather than after.
 *
 * Pointer events rather than separate mouse and touch handlers: one code path
 * covers a finger, a mouse and a stylus, and it is the only one that reports
 * drags correctly when a finger leaves the element mid-gesture.
 *
 * The server still crops to a square. It receives one already, so its crop is
 * a no-op — which is the point: nothing here is trusted, and nothing here
 * needed the server to change.
 */

/** What the cropper hands back. Larger than the 256 the server keeps, so its
 *  own resample has pixels to work with. */
const OUT_SIZE = 512;

const MAX_ZOOM = 4;

interface Props {
    /** The picked file, or null when the dialog is closed. */
    file: File | null;
    onCancel: () => void;
    onCropped: (file: File) => void;
}

export function PhotoCropper({ file, onCancel, onCropped }: Props) {
    const frameRef = useRef<HTMLDivElement>(null);
    const bitmapRef = useRef<ImageBitmap | null>(null);
    const dragRef = useRef<{ x: number; y: number } | null>(null);

    const [url, setUrl] = useState<string | null>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    const [frame, setFrame] = useState(0);
    const [zoom, setZoom] = useState(1);
    const [offset, setOffset] = useState({ x: 0, y: 0 });
    const [busy, setBusy] = useState(false);

    /* An object URL is a document-lifetime handle to the file's bytes. Not
       revoking it holds the whole image in memory for as long as the tab is
       open, which on a phone is the difference between choosing three photos
       and the tab being killed. */
    useEffect(() => {
        if (!file) return;

        const next = URL.createObjectURL(file);
        setUrl(next);

        return () => URL.revokeObjectURL(next);
    }, [file]);

    // Decoded once and kept, so dragging never re-reads the file.
    useEffect(() => {
        if (!file) return;

        let cancelled = false;

        createImageBitmap(file)
            .then((bitmap) => {
                if (cancelled) return bitmap.close();

                bitmapRef.current = bitmap;
                setNatural({ w: bitmap.width, h: bitmap.height });
                setZoom(1);
                setOffset({ x: 0, y: 0 });
            })
            .catch(() => {
                // Undecodable here means undecodable everywhere. Hand it
                // straight on and let the server say so in words.
                onCropped(file);
            });

        return () => {
            cancelled = true;
            bitmapRef.current?.close();
            bitmapRef.current = null;
        };
    }, [file, onCropped]);

    /* Measured rather than assumed: the frame is a percentage of a dialog
       that is a percentage of the viewport, and every number below is in its
       units. */
    useEffect(() => {
        const element = frameRef.current;
        if (!element) return;

        const measure = () => setFrame(element.getBoundingClientRect().width);
        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, [url]);

    /** The scale at which the photo exactly covers the frame. */
    const cover = natural && frame ? Math.max(frame / natural.w, frame / natural.h) : 0;
    const scale = cover * zoom;
    const width = natural ? natural.w * scale : 0;
    const height = natural ? natural.h * scale : 0;

    /* Clamped so the frame is never showing emptiness. Re-applied on every
       change rather than only on drag, because zooming out can strand an
       offset that was legal a moment ago. */
    const clamp = useCallback(
        (next: { x: number; y: number }) => ({
            x: Math.min(0, Math.max(frame - width, next.x)),
            y: Math.min(0, Math.max(frame - height, next.y)),
        }),
        [frame, width, height],
    );

    useEffect(() => {
        if (!width || !height) return;

        // Centred on first sight, and kept legal after that.
        setOffset((current) =>
            current.x === 0 && current.y === 0
                ? { x: (frame - width) / 2, y: (frame - height) / 2 }
                : clamp(current),
        );
    }, [width, height, frame, clamp]);

    const down = (e: React.PointerEvent) => {
        e.currentTarget.setPointerCapture(e.pointerId);
        dragRef.current = { x: e.clientX - offset.x, y: e.clientY - offset.y };
    };

    const move = (e: React.PointerEvent) => {
        const start = dragRef.current;
        if (!start) return;

        setOffset(clamp({ x: e.clientX - start.x, y: e.clientY - start.y }));
    };

    const up = () => {
        dragRef.current = null;
    };

    const changeZoom = (next: number) => {
        setZoom(next);
        // Clamping happens in the effect above once the new width is known.
    };

    /**
     * The visible square, at `OUT_SIZE`.
     *
     * The source rectangle is the frame mapped back through the same scale and
     * offset the preview is drawn with, which is what makes what you saw and
     * what you get the same thing rather than two calculations that agree most
     * of the time.
     */
    const confirm = async () => {
        const bitmap = bitmapRef.current;
        if (!bitmap || !file || !frame) return;

        setBusy(true);

        try {
            const canvas = document.createElement('canvas');
            canvas.width = OUT_SIZE;
            canvas.height = OUT_SIZE;

            const context = canvas.getContext('2d');
            if (!context) return onCropped(file);

            context.drawImage(
                bitmap,
                -offset.x / scale,
                -offset.y / scale,
                frame / scale,
                frame / scale,
                0,
                0,
                OUT_SIZE,
                OUT_SIZE,
            );

            const blob = await new Promise<Blob | null>((resolve) =>
                canvas.toBlob(resolve, 'image/webp', 0.9),
            );

            onCropped(blob ? new File([blob], 'photo', { type: blob.type }) : file);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={file !== null} onOpenChange={(open) => (open ? null : onCancel())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Position your photo</DialogTitle>
                    <DialogDescription>
                        Drag to move, and use the slider to zoom. The circle is what people see.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {/* Square and full width on a phone, capped so it cannot
                        outgrow a laptop's dialog. */}
                    <div
                        ref={frameRef}
                        onPointerDown={down}
                        onPointerMove={move}
                        onPointerUp={up}
                        onPointerCancel={up}
                        className={cn(
                            'relative mx-auto aspect-square w-full max-w-[18rem] overflow-hidden rounded-lg',
                            'bg-surface-2 touch-none select-none',
                            dragRef.current ? 'cursor-grabbing' : 'cursor-grab',
                        )}
                    >
                        {url && natural ? (
                            <img
                                src={url}
                                alt=""
                                draggable={false}
                                className="absolute max-w-none origin-top-left"
                                style={{
                                    width: `${width}px`,
                                    height: `${height}px`,
                                    transform: `translate(${offset.x}px, ${offset.y}px)`,
                                }}
                            />
                        ) : null}

                        {/* The circle the avatar is actually shown in. Drawn
                            over the photo rather than clipping it, so the parts
                            being cut are visible instead of guessed at. */}
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0 rounded-full shadow-[0_0_0_9999px_var(--page)] opacity-65"
                        />
                    </div>

                    <label className="flex items-center gap-3">
                        <span className="text-[0.8125rem] text-ink-mute">Zoom</span>
                        <input
                            type="range"
                            min={1}
                            max={MAX_ZOOM}
                            step={0.01}
                            value={zoom}
                            onChange={(e) => changeZoom(Number(e.target.value))}
                            className="h-11 flex-1 accent-[var(--fill)]"
                        />
                    </label>

                    {/* Full width and stacked on a phone; the confirming action
                        sits first so a thumb reaching up finds it. */}
                    <div className="flex flex-col gap-2 sm:flex-row-reverse">
                        <button
                            type="button"
                            onClick={confirm}
                            disabled={busy || !natural}
                            className={cn(
                                'h-11 rounded-lg bg-fill px-4 text-[0.875rem] font-medium text-fill-ink sm:h-9',
                                'transition-colors duration-(--dur-micro) ease-out',
                                'hover:bg-fill/90 disabled:opacity-45',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                            )}
                        >
                            Use photo
                        </button>
                        <button
                            type="button"
                            onClick={onCancel}
                            className={cn(
                                'h-11 rounded-lg px-4 text-[0.875rem] text-ink-mute sm:h-9',
                                'transition-colors duration-(--dur-micro) ease-out',
                                'hover:bg-surface-2 hover:text-ink',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                            )}
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
