import { BrandMark } from '@/components/chat/brand';
import { ChatActionDialog, type ChatAction } from '@/components/chat/chat-actions';
import { DeliveryMark } from '@/components/chat/delivery-mark';
import { ComposeMenu } from '@/components/chat/compose-menu';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { Input } from '@/components/ui/input';
import { conversationTitle, counterpart, listTime } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { Conversation, Message, Participant } from '@/types';
import { AtSign, ChevronDown, Eraser, Search, Trash2, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Props {
    conversations: Conversation[];
    currentUser: Participant;
    activeId: string | null;
    onSelect: (id: string) => void;
    className?: string;
}

export function ConversationList({
    conversations,
    currentUser,
    activeId,
    onSelect,
    className,
}: Props) {
    const [query, setQuery] = useState('');

    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return conversations;

        return conversations.filter((c) => {
            const inTitle = conversationTitle(c, currentUser.id).toLowerCase().includes(q);
            const inBody = c.last_message?.body?.toLowerCase().includes(q) ?? false;
            return inTitle || inBody;
        });
    }, [conversations, currentUser.id, query]);

    return (
        <div
            className={cn(
                /* Below md the three panes cannot coexist: 56px rail + 336px list
                   already overflows a 320px viewport. So the list becomes the
                   whole pane and the thread replaces it on select. */
                'flex w-full min-w-0 flex-col border-e border-line bg-page md:w-84 md:shrink-0',
                className,
            )}
        >
            {/* Generous above, tight below — the header should not compete with
                the list it introduces. */}
            <div className="px-4 pt-5 pb-3">
                <div className="flex items-center justify-between gap-2">
                    <h1 className="flex items-center gap-2 text-[1.375rem] leading-none font-semibold tracking-[-0.02em]">
                        {/* Mobile only: on desktop the rail already carries the
                            mark, and two of them would just be two of them. */}
                        <BrandMark className="h-6 text-ink-soft md:hidden" />
                        Chats
                    </h1>

                    <ComposeMenu className="-me-2" />
                </div>

                <div className="relative mt-4">
                    <Search
                        className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-ink-mute"
                        aria-hidden
                    />
                    <Input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search messages"
                        aria-label="Search messages"
                        className="h-11 rounded-full border-line bg-surface ps-9 shadow-none"
                    />
                </div>
            </div>

            {matches.length === 0 ? (
                <EmptyResults query={query} />
            ) : (
                /* max-md:pb-24 — the last row has to be able to scroll clear
                   of the floating nav, which sits over this list on mobile. */
                <ul className="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-3 max-md:pb-24">
                    {matches.map((conversation) => (
                        <ConversationRow
                            key={conversation.id}
                            conversation={conversation}
                            currentUser={currentUser}
                            selected={conversation.id === activeId}
                            onSelect={onSelect}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

function ConversationRow({
    conversation,
    currentUser,
    selected,
    onSelect,
}: {
    conversation: Conversation;
    currentUser: Participant;
    selected: boolean;
    onSelect: (id: string) => void;
}) {
    const title = conversationTitle(conversation, currentUser.id);
    const other = counterpart(conversation, currentUser.id);
    const last = conversation.last_message;
    const unread = conversation.unread_count > 0;
    const isGroup = conversation.type === 'group';
    const sentByMe = last?.user_id === currentUser.id;

    /* In a group the preview needs to name its speaker, otherwise a row of
       four group chats is four indistinguishable sentences. */
    const speaker = isGroup && last
        ? sentByMe
            ? 'You'
            : conversation.participants.find((p) => p.id === last.user_id)?.name.split(' ')[0]
        : null;

    /* One dialog per row, opened either by the hover chevron or by
       right-clicking the row. Keeping the state here rather than inside the
       menu is what lets the two share it. */
    const [action, setAction] = useState<ChatAction>(null);

    const actions: { key: ChatAction; icon: typeof Eraser; label: string; destructive?: boolean }[] = [
        { key: 'clear', icon: Eraser, label: 'Clear history' },
        ...(conversation.can.delete_chat
            ? [{ key: 'delete' as const, icon: Trash2, label: 'Delete chat', destructive: true }]
            : []),
    ];

    return (
        <li className="group/row relative">
            <ContextMenu>
            <ContextMenuTrigger asChild>
            <button
                type="button"
                onClick={() => onSelect(conversation.id)}
                aria-current={selected ? 'true' : undefined}
                className={cn(
                    'group relative flex w-full items-center gap-3 px-4 py-2.5 text-start',
                    'transition-colors duration-(--dur-micro) ease-out',
                    'focus-visible:z-1 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-info',
                    /* Selection is a two-pane idea. On a phone the list and
                       the thread are never on screen together, so a
                       highlighted row would be pointing at something you
                       cannot see — and at `/` the server picks a conversation
                       to fill the third pane, which would mark a row nobody
                       chose. Every selected style below is therefore `md:`. */
                    selected
                        ? 'max-md:hover:bg-surface-2 md:bg-fill md:text-fill-ink'
                        : 'hover:bg-surface-2 active:bg-surface-2',
                )}
            >
                {/* The divider is drawn on the row, inset to where the text
                    starts, and suppressed on the selected row and the one above
                    it — a rule running into a filled block reads as a seam. */}
                <span
                    aria-hidden
                    className={cn(
                        'absolute inset-x-4 bottom-0 h-px bg-line',
                        'group-last:hidden',
                        selected && 'md:hidden',
                    )}
                />

                {isGroup ? (
                    <span className="grid size-10 shrink-0 place-items-center rounded-full border border-line bg-surface-2 text-ink-soft">
                        <Users className="size-[1.125rem]" aria-hidden />
                    </span>
                ) : (
                    <PresenceAvatar name={title} src={other?.avatar_url} online={other?.online} />
                )}

                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="flex items-baseline gap-2">
                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-sm',
                                unread ? 'font-semibold' : 'font-medium',
                            )}
                        >
                            {title}
                        </span>

                        {last ? (
                            <time
                                dateTime={last.created_at}
                                className={cn(
                                    'shrink-0 text-[0.6875rem]',
                                    /* The actions button takes this slot on
                                       hover. Fading rather than hiding keeps
                                       the row from reflowing under the cursor. */
                                    'transition-opacity duration-(--dur-micro) ease-out group-hover/row:opacity-0',
                                    unread ? 'text-ink-soft' : 'text-ink-mute',
                                    selected && 'md:text-fill-ink-soft',
                                )}
                            >
                                {listTime(last.created_at)}
                            </time>
                        ) : null}
                    </span>

                    <span className="flex items-center gap-1.5">
                        {sentByMe && last ? (
                            <DeliveryMark
                                delivery={last.delivery}
                                className={selected ? 'md:text-fill-ink-soft' : undefined}
                            />
                        ) : null}

                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-[0.8125rem]',
                                unread ? 'text-ink-soft' : 'text-ink-mute',
                                selected && 'md:text-fill-ink-soft',
                                last?.deleted_at && 'italic',
                            )}
                        >
                            {last
                                ? `${speaker ? `${speaker}: ` : ''}${preview(last)}`
                                : 'No messages yet'}
                        </span>

                        {conversation.mentioned ? (
                            <span
                                className={cn(
                                    'grid size-4.5 shrink-0 place-items-center rounded-full',
                                    'bg-info/12 text-info',
                                    selected && 'md:bg-fill-ink md:text-fill',
                                )}
                            >
                                <AtSign className="size-3" aria-hidden />
                                <span className="sr-only">You were mentioned</span>
                            </span>
                        ) : null}

                        {unread ? (
                            <span
                                data-tabular
                                className={cn(
                                    'grid h-4.5 min-w-4.5 shrink-0 place-items-center rounded-full px-1 text-[0.6875rem] font-semibold',
                                    'bg-ink text-ink-ink',
                                    selected && 'md:bg-fill-ink md:text-fill',
                                )}
                            >
                                {conversation.unread_count > 99 ? '99+' : conversation.unread_count}
                                <span className="sr-only">unread</span>
                            </span>
                        ) : null}
                    </span>
                </span>
            </button>
            </ContextMenuTrigger>

            <ContextMenuContent>
                {actions.map((a) => (
                    <ContextMenuItem
                        key={a.key}
                        variant={a.destructive ? 'destructive' : 'default'}
                        onSelect={() => setAction(a.key)}
                    >
                        <a.icon aria-hidden />
                        {a.label}
                    </ContextMenuItem>
                ))}
            </ContextMenuContent>
            </ContextMenu>

            <RowMenu
                label={`Actions for ${title}`}
                actions={actions}
                selected={selected}
                onPick={setAction}
            />

            <ChatActionDialog
                conversation={conversation}
                currentUser={currentUser}
                action={action}
                onClose={() => setAction(null)}
            />
        </li>
    );
}

/**
 * Reachable without opening the chat first, which is the point: tidying the
 * list is something you do to rows you are not reading. The same actions are
 * on the row's right-click menu; this one exists because right-click is not
 * discoverable and does not exist on touch.
 *
 * Positioned over the timestamp rather than given a column of its own — a
 * column that appears on hover moves every row under the cursor.
 */
function RowMenu({
    label,
    actions,
    selected,
    onPick,
}: {
    label: string;
    actions: { key: ChatAction; icon: typeof Eraser; label: string; destructive?: boolean }[];
    selected: boolean;
    onPick: (action: ChatAction) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={label}
                className={cn(
                    'absolute end-2 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-full',
                    'opacity-0 transition-opacity duration-(--dur-micro) ease-out',
                    'group-hover/row:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    'bg-surface-2 text-ink-mute',
                    selected && 'md:bg-fill md:text-fill-ink-soft',
                )}
            >
                <ChevronDown className="size-4" aria-hidden />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-48">
                {actions.map((a) => (
                    <DropdownMenuItem
                        key={a.key}
                        variant={a.destructive ? 'destructive' : 'default'}
                        onSelect={() => onPick(a.key)}
                    >
                        <a.icon aria-hidden />
                        {a.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A null body used to mean "attachment only". Since messages can be deleted it
 * can also mean a tombstone, and calling that an attachment is a lie the row
 * tells at a glance.
 */
function preview(message: Message): string {
    if (message.deleted_at) return 'This message was deleted';

    return message.body ?? 'Attachment';
}

function EmptyResults({ query }: { query: string }) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 px-8 pb-16 text-center">
            <Search className="size-5 text-ink-mute" aria-hidden />
            <p className="text-sm font-medium">No chats matched</p>
            <p className="text-[0.8125rem] text-ink-mute">
                Nothing here for “{query}”. Try a shorter word, or a person’s name.
            </p>
        </div>
    );
}
