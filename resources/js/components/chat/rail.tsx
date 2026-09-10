import { APP_NAME, BrandMark } from '@/components/chat/brand';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Camera, LogOut, MessagesSquare, Phone, SlidersHorizontal } from 'lucide-react';
import type { ComponentType } from 'react';

interface Destination {
    key: string;
    label: string;
    Icon: ComponentType<{ className?: string }>;
}

const DESTINATIONS: Destination[] = [
    { key: 'calls', label: 'Calls', Icon: Phone },
    { key: 'chats', label: 'Chats', Icon: MessagesSquare },
    { key: 'status', label: 'Status', Icon: Camera },
    { key: 'settings', label: 'Settings', Icon: SlidersHorizontal },
];

/**
 * The rail is dark in both themes. It is chrome, not content, and in a palette
 * with no brand hue the only way left to say "this is not the page" is weight.
 *
 * Icon-only navigation is a real accessibility hazard, so each button carries a
 * `sr-only` label *and* a tooltip — the tooltip is the sighted affordance, the
 * label is the one that actually ships to assistive tech.
 */
export function Rail({ active = 'chats', className }: { active?: string; className?: string }) {
    return (
        <nav
            aria-label="Sections"
            className={cn(
                'flex w-14 shrink-0 flex-col items-center gap-1 border-e border-line bg-rail py-3',
                className,
            )}
        >
            <span className="flex h-7 items-center text-rail-ink-on">
                <BrandMark className="h-7" />
                <span className="sr-only">{APP_NAME}</span>
            </span>
            <span aria-hidden className="my-3 h-px w-6 bg-rail-ink-on/15" />

            {DESTINATIONS.map(({ key, label, Icon }) => {
                const isActive = key === active;

                return (
                    <Tooltip key={key}>
                        <TooltipTrigger
                            type="button"
                            aria-current={isActive ? 'page' : undefined}
                            className={cn(
                                'grid size-11 place-items-center rounded-lg',
                                'transition-colors duration-(--dur-micro) ease-out',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                                isActive
                                    ? 'bg-rail-ink-on/12 text-rail-ink-on'
                                    : 'text-rail-ink hover:bg-rail-ink-on/8 hover:text-rail-ink-on active:bg-rail-ink-on/15',
                            )}
                        >
                            <Icon className="size-5" aria-hidden />
                            <span className="sr-only">{label}</span>
                        </TooltipTrigger>
                        <TooltipContent side="right">{label}</TooltipContent>
                    </Tooltip>
                );
            })}

            {/* ponytail: sign-out is desktop-only until a Settings page exists
                to hold it — the owner chose that over putting a destructive
                action in the thumb zone. Until then there is no way to sign
                out on a phone. */}
            <Tooltip>
                <TooltipTrigger
                    type="button"
                    onClick={() => router.post('/logout')}
                    className={cn(
                        'mt-auto grid size-11 place-items-center rounded-lg text-rail-ink',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-rail-bad/15 hover:text-rail-bad active:bg-rail-bad/25',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    <LogOut className="size-5" aria-hidden />
                    <span className="sr-only">Sign out</span>
                </TooltipTrigger>
                <TooltipContent side="right">Sign out</TooltipContent>
            </Tooltip>
        </nav>
    );
}

/**
 * The same destinations, laid along the bottom instead of down the side.
 *
 * Below `md` there is no room for a 56px column beside a conversation — it
 * takes a sixth of a phone and gives back nothing while you are reading. So
 * the rail is not narrowed on mobile, it is moved: out of the thread
 * entirely, and onto the list, which is the only place it has anything to
 * navigate away from.
 *
 * Floating rather than docked, so the list is visibly still running
 * underneath rather than stopping at a wall.
 */
export function FloatingNav({ active = 'chats' }: { active?: string }) {
    return (
        <nav
            aria-label="Sections"
            className={cn(
                'fixed bottom-4 left-1/2 z-20 -translate-x-1/2 md:hidden',
                'flex items-center gap-1 rounded-full bg-rail px-2 py-1.5 shadow-soft-lg',
            )}
        >
            {DESTINATIONS.map(({ key, label, Icon }) => {
                const isActive = key === active;

                return (
                    <button
                        key={key}
                        type="button"
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            /* 44px: the smallest target a thumb reliably hits.
                               There are no tooltips on a touch screen, so the
                               sr-only label is the only name these have. */
                            'grid size-11 place-items-center rounded-full',
                            'transition-colors duration-(--dur-micro) ease-out',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                            isActive
                                ? 'bg-rail-ink-on/12 text-rail-ink-on'
                                : 'text-rail-ink active:bg-rail-ink-on/15',
                        )}
                    >
                        <Icon className="size-5" aria-hidden />
                        <span className="sr-only">{label}</span>
                    </button>
                );
            })}
        </nav>
    );
}
