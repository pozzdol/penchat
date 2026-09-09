import { APP_NAME, BrandMark } from '@/components/chat/brand';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
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
export function Rail({ active = 'chats' }: { active?: string }) {
    return (
        <nav
            aria-label="Sections"
            className="flex w-14 shrink-0 flex-col items-center gap-1 border-e border-line bg-rail py-3"
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

            <Tooltip>
                <TooltipTrigger
                    type="button"
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
