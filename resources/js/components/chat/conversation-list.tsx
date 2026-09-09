import { DeliveryMark } from '@/components/chat/delivery-mark';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { Input } from '@/components/ui/input';
import { conversationTitle, counterpart, listTime } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { Conversation, Participant } from '@/types';
import { AtSign, Search, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Props {
    conversations: Conversation[];
    currentUser: Participant;
    activeId: number | null;
    onSelect: (id: number) => void;
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
                <h1 className="text-[1.375rem] leading-none font-semibold tracking-[-0.02em]">
                    Chats
                </h1>

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
                <ul className="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-3">
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
    onSelect: (id: number) => void;
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

    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(conversation.id)}
                aria-current={selected ? 'true' : undefined}
                className={cn(
                    'group relative flex w-full items-center gap-3 px-4 py-2.5 text-start',
                    'transition-colors duration-(--dur-micro) ease-out',
                    'focus-visible:z-1 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-info',
                    selected
                        ? 'bg-fill text-fill-ink'
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
                        selected && 'hidden',
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
                                    selected ? 'text-fill-ink-soft' : unread ? 'text-ink-soft' : 'text-ink-mute',
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
                                className={selected ? 'text-fill-ink-soft' : undefined}
                            />
                        ) : null}

                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-[0.8125rem]',
                                selected
                                    ? 'text-fill-ink-soft'
                                    : unread
                                      ? 'text-ink-soft'
                                      : 'text-ink-mute',
                            )}
                        >
                            {last
                                ? `${speaker ? `${speaker}: ` : ''}${last.body ?? 'Attachment'}`
                                : 'No messages yet'}
                        </span>

                        {conversation.mentioned ? (
                            <span
                                className={cn(
                                    'grid size-4.5 shrink-0 place-items-center rounded-full',
                                    selected ? 'bg-fill-ink/20 text-fill-ink' : 'bg-info/12 text-info',
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
                                    selected ? 'bg-fill-ink text-fill' : 'bg-ink text-ink-ink',
                                )}
                            >
                                {conversation.unread_count > 99 ? '99+' : conversation.unread_count}
                                <span className="sr-only">unread</span>
                            </span>
                        ) : null}
                    </span>
                </span>
            </button>
        </li>
    );
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
