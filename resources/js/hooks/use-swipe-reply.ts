import { useCallback, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';

/**
 * Drag a message to the right to reply, the way a phone expects.
 *
 * Three things make this work rather than merely function:
 *
 * **It does not fight the scroll.** The thread scrolls vertically through the
 * same fingers. Nothing is captured until the movement is clearly sideways,
 * and `touch-action: pan-y` leaves vertical panning to the browser — where it
 * belongs, because a hand-rolled scroll is always worse than a native one.
 *
 * **It resists.** Past the trigger point the row keeps moving but at a third
 * of the speed, so the gesture has a floor you can feel instead of a number
 * you have to guess.
 *
 * **It is touch only.** A mouse has the right-click menu; giving it a drag as
 * well would make text selection inside a bubble impossible.
 */

/** Where the gesture fires. Roughly a thumb's width of travel. */
const TRIGGER_PX = 56;

/** Movement must beat this much vertical wander before it counts as a swipe. */
const DIRECTION_BIAS = 8;

export function useSwipeReply(onReply: () => void, enabled = true) {
    const [offset, setOffset] = useState(0);
    const start = useRef<{ x: number; y: number } | null>(null);
    const decided = useRef<'swipe' | 'scroll' | null>(null);

    const reset = useCallback(() => {
        start.current = null;
        decided.current = null;
        setOffset(0);
    }, []);

    const onPointerDown = useCallback(
        (e: ReactPointerEvent<HTMLElement>) => {
            // Mouse keeps its context menu and its text selection.
            if (!enabled || e.pointerType === 'mouse') return;

            start.current = { x: e.clientX, y: e.clientY };
            decided.current = null;
        },
        [enabled],
    );

    const onPointerMove = useCallback((e: ReactPointerEvent<HTMLElement>) => {
        if (!start.current) return;

        const dx = e.clientX - start.current.x;
        const dy = e.clientY - start.current.y;

        if (decided.current === null) {
            // Undecided until one axis clearly wins. Guessing early is what
            // makes a gesture steal scrolls.
            if (Math.abs(dx) < DIRECTION_BIAS && Math.abs(dy) < DIRECTION_BIAS) return;

            decided.current = dx > Math.abs(dy) ? 'swipe' : 'scroll';

            if (decided.current === 'swipe') {
                e.currentTarget.setPointerCapture(e.pointerId);
            }
        }

        if (decided.current !== 'swipe') return;

        // Rightward only, and heavier past the trigger.
        const travel = Math.max(0, dx);
        setOffset(travel <= TRIGGER_PX ? travel : TRIGGER_PX + (travel - TRIGGER_PX) / 3);
    }, []);

    const onPointerUp = useCallback(() => {
        if (decided.current === 'swipe' && offset >= TRIGGER_PX) {
            onReply();
        }

        reset();
    }, [offset, onReply, reset]);

    return {
        /** Spread onto the row that should follow the finger. */
        handlers: {
            onPointerDown,
            onPointerMove,
            onPointerUp,
            onPointerCancel: reset,
        },
        /** Current travel in pixels. Zero when idle. */
        offset,
        /** 0 to 1, for fading the reply arrow in as the gesture approaches. */
        progress: Math.min(1, offset / TRIGGER_PX),
        /** True once releasing would fire. */
        armed: offset >= TRIGGER_PX,
    };
}
