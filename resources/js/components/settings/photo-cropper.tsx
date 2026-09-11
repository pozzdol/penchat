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
 * Without this the server centre-cropped whatever arrived and the person
 * uploading had no way to know what survived — a portrait taken in landscape
 * lost both ears, and the only way to find out was to look afterwards. The
 * frame here shows exactly the pixels that will be kept, at the moment of
 * choosing rather than after.
 *
 * The `<img>` on screen is also the source the export draws from, so there is
 * one decode, one set of dimensions, and no way for the preview and the result
 * to disagree. An earlier version decoded separately with `createImageBitmap`
 * and never showed anything at all: the effect holding that promise listed a
 * callback prop among its dependencies, the parent passed a fresh arrow
 * function on every render, and so every decode was cancelled by the re-render
 * that followed it. Nothing here is allowed to depend on the caller
 * remembering to memoise.
 *
 * Pointer events rather than separate mouse and touch handlers: one code path
 * covers a finger, a mouse and a stylus, and it is the only one that keeps
 * reporting a drag after the finger leaves the element.
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
    const imageRef = useRef<HTMLImageElement | null>(null);
    const dragRef = useRef<{ x: number; y: number } | null>(null);
    const observerRef = useRef<ResizeObserver | null>(null);

    const [url, setUrl] = useState<string | null>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    const [frame, setFrame] = useState(0);
    const [zoom, setZoom] = useState(1);
    const [offset, setOffset] = useState({ x: 0, y: 0 });
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    /* Held in a ref so no effect has to name it as a dependency. That listing
       is what broke this once already, and a ref cannot break the same way
       whatever the parent does with its own renders. */
    const handOver = useRef(onCropped);
    useEffect(() => {
        handOver.current = onCropped;
    });

    /* An object URL is a document-lifetime handle to the file's bytes. Not
       revoking it holds the whole image in memory for as long as the tab is
       open, which on a phone is the difference between choosing three photos
       and the tab being killed. */
    useEffect(() => {
        if (!file) {
            setUrl(null);

            return;
        }

        const next = URL.createObjectURL(file);

        setUrl(next);
        setNatural(null);
        setFailed(false);
        setZoom(1);
        setOffset({ x: 0, y: 0 });

        return () => URL.revokeObjectURL(next);
    }, [file]);

    /**
     * Measured by a callback ref, not by an effect reading a ref.
     *
     * Radix renders the dialog through a portal, so there is no guarantee the
     * node exists by the time an effect runs — and the effect this replaces
     * bailed out silently when it did not, leaving the frame at zero. A frame
     * of zero makes `cover` zero, which makes the photo zero pixels wide and
     * `ready` false: an invisible image above a dead button, which is exactly
     * what this looked like. A callback ref fires the moment React attaches
     * the node, whenever that happens to be.
     */
    const attachFrame = useCallback((node: HTMLDivElement | null) => {
        observerRef.current?.disconnect();
        observerRef.current = null;

        if (!node) return;

        /* `offsetWidth`, not `getBoundingClientRect`: the dialog animates in
           with a scale transform, and the rect reports the transformed width
           mid-animation — a frame that stops existing a moment later. */
        const measure = () => setFrame(node.offsetWidth);

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(node);
        observerRef.current = observer;
    }, []);

    useEffect(() => () => observerRef.current?.disconnect(), []);


    /** The scale at which the photo exactly covers the frame. */
    const cover = natural && frame ? Math.max(frame / natural.w, frame / natural.h) : 0;
    const scale = cover * zoom;
    const width = natural ? natural.w * scale : 0;
    const height = natural ? natural.h * scale : 0;

    /* Clamped so the frame never shows emptiness. Re-applied on every change
       rather than only on drag, because zooming out can strand an offset that
       was legal a moment ago. */
    const clamp = useCallback(
        (next: { x: number; y: number }) => ({
            x: Math.min(0, Math.max(frame - width, next.x)),
            y: Math.min(0, Math.max(frame - height, next.y)),
        }),
        [frame, width, height],
    );

    /**
     * `onLoad` alone is not enough: a picture the browser has already decoded
     * can be `complete` before React attaches the handler, and then the event
     * never comes. The callback ref catches that case, the handler catches the
     * ordinary one, and both write the same state.
     */
    const attachImage = useCallback((node: HTMLImageElement | null) => {
        imageRef.current = node;

        if (node?.complete && node.naturalWidth > 0) {
            setNatural({ w: node.naturalWidth, h: node.naturalHeight });
        }
    }, []);

    const ready = natural !== null && frame > 0;

    // Centred on first sight, and kept legal after that.
    useEffect(() => {
        if (!width || !height) return;

        setOffset((current) =>
            current.x === 0 && current.y === 0
                ? { x: (frame - width) / 2, y: (frame - height) / 2 }
                : clamp(current),
        );
    }, [width, height, frame, clamp]);

    const down = (e: React.PointerEvent) => {
        if (!ready) return;

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

    /**
     * The visible square, at `OUT_SIZE`.
     *
     * The source rectangle is the frame mapped back through the same scale and
     * offset the preview is drawn with, from the same element — which is what
     * makes what you saw and what you get one calculation rather than two that
     * agree most of the time.
     */
    const confirm = async () => {
        const image = imageRef.current;
        if (!image || !file || !ready) return;

        setBusy(true);

        try {
            const canvas = document.createElement('canvas');
            canvas.width = OUT_SIZE;
            canvas.height = OUT_SIZE;

            const context = canvas.getContext('2d');

            if (!context) {
                handOver.current(file);

                return;
            }

            context.drawImage(
                image,
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

            handOver.current(blob ? new File([blob], 'photo', { type: blob.type }) : file);
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
                        ref={attachFrame}
                        onPointerDown={down}
                        onPointerMove={move}
                        onPointerUp={up}
                        onPointerCancel={up}
                        className={cn(
                            'relative mx-auto aspect-square w-full max-w-[18rem] overflow-hidden rounded-lg',
                            'touch-none bg-surface-2 select-none',
                            ready ? 'cursor-grab' : 'cursor-default',
                        )}
                    >
                        {/*
                            Rendered as soon as there is a URL, and never gated
                            on the dimensions it is the one thing that can
                            report. Waiting for `natural` before mounting it was
                            the other half of the bug: `onLoad` cannot fire on
                            an element that is not there, so the dimensions
                            never arrived and the image never mounted.
                        */}
                        {url ? (
                            <img
                                ref={attachImage}
                                src={url}
                                alt=""
                                draggable={false}
                                onLoad={(e) =>
                                    setNatural({
                                        w: e.currentTarget.naturalWidth,
                                        h: e.currentTarget.naturalHeight,
                                    })
                                }
                                /* Says so, rather than closing the dialog and
                                   uploading something the person never got to
                                   position — and rather than leaving a blank
                                   square above a button that will not respond,
                                   which is unactionable. */
                                onError={() => setFailed(true)}
                                className={cn(
                                    'absolute max-w-none origin-top-left',
                                    ready ? 'opacity-100' : 'opacity-0',
                                )}
                                style={
                                    ready
                                        ? {
                                              width: `${width}px`,
                                              height: `${height}px`,
                                              transform: `translate(${offset.x}px, ${offset.y}px)`,
                                          }
                                        : undefined
                                }
                            />
                        ) : null}

                        {/* The circle the avatar is actually shown in. Drawn
                            over the photo rather than clipping it, so the parts
                            being cut are visible instead of guessed at. */}
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0 rounded-full opacity-65 shadow-[0_0_0_9999px_var(--page)]"
                        />
                    </div>

                    {failed ? (
                        <p role="alert" className="text-[0.8125rem] leading-snug text-bad">
                            That image could not be opened. Try a different photo.
                        </p>
                    ) : null}

                    <label className="flex items-center gap-3">
                        <span className="text-[0.8125rem] text-ink-mute">Zoom</span>
                        <input
                            type="range"
                            min={1}
                            max={MAX_ZOOM}
                            step={0.01}
                            value={zoom}
                            disabled={!ready}
                            onChange={(e) => setZoom(Number(e.target.value))}
                            className="h-11 flex-1 accent-[var(--fill)]"
                        />
                    </label>

                    {/* Full width and stacked on a phone; the confirming action
                        sits first so a thumb reaching up finds it. */}
                    <div className="flex flex-col gap-2 sm:flex-row-reverse">
                        <button
                            type="button"
                            onClick={confirm}
                            disabled={busy || !ready}
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
