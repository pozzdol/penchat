import { cn } from '@/lib/utils';
import type { MessageQuote } from '@/types';

/**
 * The quoted message, above the reply that carries it.
 *
 * A recess, not a card. It uses `--quote` / `--quote-on-fill`, which sit one
 * fixed lightness step off whichever bubble holds them — the same distance in
 * both themes, downward on paper and upward in the dark, because a dark
 * bubble already sits near the floor and has nowhere below to go.
 *
 * No rule down the edge and no border. With a ground of its own the bar was a
 * second signifier for the same thing, and the bubble already has an outline
 * where it needs one.
 *
 * Author and words share a colour on purpose. Hierarchy is size and weight
 * here; the earlier version bought it with a softer ink on a translucent tint
 * and measured 3.79:1 — the same alpha trap that dragged the list timestamps
 * under. Contrast is not a currency to spend on emphasis.
 */
export function Quote({
    quote,
    mine,
    onJump,
}: {
    quote: MessageQuote;
    /** Sitting on the filled bubble rather than on paper. */
    mine: boolean;
    /** Absent when the original is no longer in the loaded thread. */
    onJump?: () => void;
}) {
    const body = quote.deleted ? 'This message was deleted' : (quote.body ?? 'Attachment');

    return (
        <button
            type="button"
            data-slot="quote"
            onClick={onJump}
            disabled={!onJump}
            className={cn(
                /* Tighter than the bubble's own radius, so it reads as set
                   into it rather than stacked on top. */
                'mb-1.5 flex w-full flex-col gap-0.5 rounded-lg px-2.5 py-1.5 text-start',
                'transition-colors duration-(--dur-micro) ease-out',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                mine ? 'bg-quote-on-fill text-fill-ink' : 'bg-quote text-ink',
                onJump ? 'hover:brightness-[0.97]' : 'cursor-default',
            )}
        >
            <span className="text-[0.6875rem] font-semibold">{quote.author ?? 'Unknown'}</span>
            <span
                className={cn(
                    'line-clamp-2 text-[0.75rem] [overflow-wrap:anywhere]',
                    quote.deleted && 'italic',
                )}
            >
                {body}
            </span>
        </button>
    );
}
