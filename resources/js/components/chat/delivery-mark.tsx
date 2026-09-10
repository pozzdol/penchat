import { Check, CheckCheck, CircleAlert, Clock } from 'lucide-react';
import type { MessageDelivery } from '@/types';
import { cn } from '@/lib/utils';

/**
 * Shape carries the meaning, colour only sharpens it — so the row still reads
 * for a colour-blind reader and in a greyscale print.
 *
 * The *count* of ticks says the message arrived; that separates sent from
 * delivered without any colour at all. Delivered and read then share a glyph,
 * and their two tones measure 1.003:1 against each other — identical once the
 * hue is gone. Colour cannot be their only difference, so read is also drawn
 * heavier. Weight survives greyscale, and it reads as emphasis rather than as
 * a fourth unrelated symbol.
 */
const MARKS = {
    pending: { Icon: Clock, tone: 'text-warn', weight: 2, label: 'Sending' },
    sent: { Icon: Check, tone: 'text-ink-mute', weight: 2, label: 'Sent' },
    delivered: { Icon: CheckCheck, tone: 'text-ink-mute', weight: 2, label: 'Delivered' },
    read: { Icon: CheckCheck, tone: 'text-ok', weight: 3.25, label: 'Read' },
    failed: { Icon: CircleAlert, tone: 'text-bad', weight: 2, label: 'Not sent' },
} as const satisfies Record<MessageDelivery, unknown>;

export function DeliveryMark({
    delivery,
    className,
}: {
    delivery: MessageDelivery;
    className?: string;
}) {
    const { Icon, tone, weight, label } = MARKS[delivery];

    return (
        <span className={cn('inline-flex items-center gap-1', tone, className)}>
            <Icon className="size-3.5" strokeWidth={weight} aria-hidden />
            <span className="sr-only">{label}</span>
        </span>
    );
}

export const deliveryLabel = (delivery: MessageDelivery): string => MARKS[delivery].label;
