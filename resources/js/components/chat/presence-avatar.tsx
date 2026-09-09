import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import { initials } from '@/lib/chat';

const SIZES = {
    sm: { avatar: 'size-8', dot: 'size-2.5', text: 'text-[0.6875rem]' },
    md: { avatar: 'size-10', dot: 'size-3', text: 'text-xs' },
    lg: { avatar: 'size-11', dot: 'size-3', text: 'text-sm' },
} as const;

interface Props {
    name: string;
    src?: string | null;
    /** Omit entirely for a group — a group has no single presence to report. */
    online?: boolean;
    size?: keyof typeof SIZES;
    className?: string;
}

/**
 * Green appears here and on the read receipt, nowhere else. Presence is the one
 * thing on the page that has to be readable before any word is.
 *
 * The dot is not colour alone: it is a filled disc on a paper-coloured ring,
 * so it survives both a monochrome render and a green-blind reader — the ring
 * is what makes it a *state* rather than a decorative pixel.
 */
export function PresenceAvatar({ name, src, online, size = 'md', className }: Props) {
    const s = SIZES[size];

    return (
        <span className={cn('relative inline-flex shrink-0', className)}>
            <Avatar className={cn(s.avatar, 'border border-line')}>
                {src ? <AvatarImage src={src} alt="" /> : null}
                <AvatarFallback className={cn('bg-surface-2 font-medium text-ink-soft', s.text)}>
                    {initials(name)}
                </AvatarFallback>
            </Avatar>

            {online === undefined ? null : (
                <span
                    className={cn(
                        'absolute -end-0.5 -bottom-0.5 rounded-full border-2 border-page',
                        s.dot,
                        online ? 'bg-ok' : 'bg-control',
                    )}
                >
                    <span className="sr-only">{online ? 'Online' : 'Offline'}</span>
                </span>
            )}
        </span>
    );
}
