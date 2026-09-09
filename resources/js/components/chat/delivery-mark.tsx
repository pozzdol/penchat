import { Check, CheckCheck, CircleAlert, Clock } from 'lucide-react';
import type { MessageDelivery } from '@/types';
import { cn } from '@/lib/utils';

/**
 * Four states, four glyphs — never four colours alone. The shape carries the
 * meaning first (one tick, two ticks, a clock, a warning) and the hue only
 * sharpens it, so the row still reads for a colour-blind reader and in a
 * greyscale print.
 */
const MARKS = {
    pending: { Icon: Clock, tone: 'text-warn', label: 'Sending' },
    sent: { Icon: Check, tone: 'text-ink-mute', label: 'Sent' },
    read: { Icon: CheckCheck, tone: 'text-ok', label: 'Read' },
    failed: { Icon: CircleAlert, tone: 'text-bad', label: 'Not sent' },
} as const satisfies Record<MessageDelivery, unknown>;

export function DeliveryMark({
    delivery,
    className,
}: {
    delivery: MessageDelivery;
    className?: string;
}) {
    const { Icon, tone, label } = MARKS[delivery];

    return (
        <span className={cn('inline-flex items-center gap-1', tone, className)}>
            <Icon className="size-3.5" aria-hidden />
            <span className="sr-only">{label}</span>
        </span>
    );
}

export const deliveryLabel = (delivery: MessageDelivery): string => MARKS[delivery].label;
