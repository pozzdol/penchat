import { NewChatDialog } from '@/components/chat/new-chat-dialog';
import { NewGroupDialog } from '@/components/chat/new-group-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { SquarePen, User, Users } from 'lucide-react';
import { useState } from 'react';

type Sheet = 'chat' | 'group' | null;

/**
 * One entry point for everything that means "start something", so the list
 * header keeps a single affordance instead of two near-identical icons.
 *
 * The dialogs are siblings of the menu rather than children of its items: a
 * menu item closes the menu when it is chosen, which would unmount a dialog
 * nested inside it before it ever painted.
 */
export function ComposeMenu({ className }: { className?: string }) {
    const [sheet, setSheet] = useState<Sheet>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    className={cn(
                        'grid size-11 shrink-0 place-items-center rounded-full text-ink-mute',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-surface-2 hover:text-ink active:bg-surface-2',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                        className,
                    )}
                >
                    <SquarePen className="size-5" aria-hidden />
                    <span className="sr-only">Start a new conversation</span>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem onSelect={() => setSheet('chat')}>
                        <User aria-hidden />
                        New chat
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setSheet('group')}>
                        <Users aria-hidden />
                        New group
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <NewChatDialog
                open={sheet === 'chat'}
                onOpenChange={(open) => setSheet(open ? 'chat' : null)}
            />
            <NewGroupDialog
                open={sheet === 'group'}
                onOpenChange={(open) => setSheet(open ? 'group' : null)}
            />
        </>
    );
}
