import { DeliveryMark, deliveryLabel } from '@/components/chat/delivery-mark';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { BrandLockup } from '@/components/chat/brand';
import { ComposeMenu } from '@/components/chat/compose-menu';
import { Button } from '@/components/ui/button';
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
import { buildThread, conversationTitle, counterpart, time, type ThreadItem } from '@/lib/chat';
import { cn } from '@/lib/utils';
import { EDIT_WINDOW_MS, type Conversation, type Message, type Participant } from '@/types';
import { ChevronDown, Copy, EyeOff, Pencil, Trash2, type LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';

interface Props {
    conversation: Conversation;
    items: ThreadItem[];
    currentUser: Participant;
    /** Names of people currently composing. Never persisted — a whisper only. */
    typing: string[];
    onRetry: (messageId: string) => void;
    onEdit: (message: Message) => void;
    /** `everyone: false` hides it for this reader only. */
    onDelete: (message: Message, everyone: boolean) => void;
}

export function Thread({ conversation, items, currentUser, typing, onRetry, onEdit, onDelete }: Props) {
    const endRef = useRef<HTMLDivElement>(null);
    const lastId = items.at(-1)?.message.id;

    /* Pin to the newest message. Keyed on the last id rather than the array so
       a re-render that changes nothing does not yank a reader out of history. */
    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [lastId]);

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain">
            <div className="mx-auto flex w-full max-w-[110rem] flex-1 flex-col justify-end gap-0.5 px-4 py-6 md:px-6">
                {items.map((item) => (
                    <MessageRow
                        key={item.message.id}
                        item={item}
                        conversation={conversation}
                        currentUser={currentUser}
                        onRetry={onRetry}
                        onEdit={onEdit}
                        onDelete={onDelete}
                    />
                ))}

                {typing.length > 0 ? <TypingIndicator names={typing} /> : null}

                <div ref={endRef} />
            </div>
        </div>
    );
}

interface Action {
    key: string;
    icon: LucideIcon;
    label: string;
    destructive?: boolean;
    run: () => void;
}

/**
 * What this message offers, decided once and rendered by two different
 * primitives — the hover chevron and the right-click menu. Defining the
 * items twice is how the two quietly drift apart.
 *
 * `Copy text` is here because taking over the right-click menu takes away the
 * browser's own, and copying a message is the most ordinary thing anyone
 * wants from it. Removing an affordance while adding a menu would be a net
 * loss.
 */
function messageActions(
    message: Message,
    conversation: Conversation,
    mine: boolean,
    deleted: boolean,
    onEdit: (message: Message) => void,
    onDelete: (message: Message, everyone: boolean) => void,
): Action[] {
    const actions: Action[] = [];

    if (message.body) {
        actions.push({
            key: 'copy',
            icon: Copy,
            label: 'Copy text',
            run: () => void navigator.clipboard?.writeText(message.body ?? ''),
        });
    }

    const editable =
        mine && !deleted && Date.now() - new Date(message.created_at).getTime() < EDIT_WINDOW_MS;

    if (editable) {
        actions.push({ key: 'edit', icon: Pencil, label: 'Edit', run: () => onEdit(message) });
    }

    actions.push({
        key: 'mine',
        icon: EyeOff,
        label: 'Delete for me',
        run: () => onDelete(message, false),
    });

    if (!deleted && (mine || conversation.can.delete_any_message)) {
        actions.push({
            key: 'everyone',
            icon: Trash2,
            label: 'Delete for everyone',
            destructive: true,
            run: () => {
                if (window.confirm('Delete this message for everyone? It cannot be undone.')) {
                    onDelete(message, true);
                }
            },
        });
    }

    return actions;
}

function MessageRow({
    item,
    conversation,
    currentUser,
    onRetry,
    onEdit,
    onDelete,
}: {
    item: ThreadItem;
    conversation: Conversation;
    currentUser: Participant;
    onRetry: (messageId: string) => void;
    onEdit: (message: Message) => void;
    onDelete: (message: Message, everyone: boolean) => void;
}) {
    const { message, author, startsRun, endsRun, dayBreak } = item;
    const mine = message.user_id === currentUser.id;
    const isGroup = conversation.type === 'group';
    const failed = message.delivery === 'failed';
    const deleted = message.deleted_at !== null;
    // Nothing the server has not acknowledged can be edited or deleted: there
    // is no row behind it yet to act on.
    const settled =
        message.delivery === 'sent' ||
        message.delivery === 'delivered' ||
        message.delivery === 'read';
    const actions = messageActions(message, conversation, mine, deleted, onEdit, onDelete);

    return (
        <>
            {dayBreak ? <DayDivider label={dayBreak} /> : null}

            <div
                className={cn(
                    'group/msg flex items-end gap-2',
                    mine ? 'flex-row-reverse' : 'flex-row',
                    startsRun && !dayBreak && 'mt-3',
                )}
            >
                {/* The avatar column is held open for every message in a run so
                    the bubbles stay on one axis; only the first row fills it. */}
                <span className="w-8 shrink-0">
                    {!mine && endsRun ? (
                        <PresenceAvatar name={author?.name ?? '?'} src={author?.avatar_url} size="sm" />
                    ) : null}
                </span>

                <div
                    className={cn(
                        'flex min-w-0 max-w-[min(32rem,78%)] flex-col gap-1',
                        mine ? 'items-end' : 'items-start',
                    )}
                >
                    {isGroup && !mine && startsRun ? (
                        <span className="px-1 text-[0.6875rem] font-medium text-ink-mute">
                            {author?.name ?? 'Unknown'}
                        </span>
                    ) : null}

                    <ContextMenu>
                    <ContextMenuTrigger asChild disabled={!settled}>
                    <div
                        className={cn(
                            'rounded-2xl px-3.5 py-2 text-[0.875rem] leading-[1.45]',
                            /* Own vs theirs cannot be a hue here, so it is
                               contrast: mine is the filled block, theirs is
                               paper with a hairline. The squared corner marks
                               the end of the run, the way a tail would. */
                            mine
                                ? 'bg-fill text-fill-ink'
                                : 'border border-line bg-surface text-ink',
                            endsRun && (mine ? 'rounded-ee-md' : 'rounded-es-md'),
                            failed && 'border border-bad',
                            /* A tombstone keeps the shape and loses the
                               substance: dashed and unfilled, so the gap is
                               legible as an absence rather than a message. */
                            deleted && 'border border-dashed border-line bg-transparent text-ink-mute',
                        )}
                    >
                        {deleted ? (
                            <p className="italic">This message was deleted</p>
                        ) : (
                            <p className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                                {message.body}
                                {message.edited_at ? (
                                    <span
                                        className={cn(
                                            'ms-1.5 align-baseline text-[0.6875rem]',
                                            mine ? 'text-fill-ink-soft' : 'text-ink-mute',
                                        )}
                                    >
                                        edited
                                    </span>
                                ) : null}
                            </p>
                        )}
                    </div>
                    </ContextMenuTrigger>

                    <ContextMenuContent>
                        {actions.map((a) => (
                            <ContextMenuItem
                                key={a.key}
                                variant={a.destructive ? 'destructive' : 'default'}
                                onSelect={a.run}
                            >
                                <a.icon aria-hidden />
                                {a.label}
                            </ContextMenuItem>
                        ))}
                    </ContextMenuContent>
                    </ContextMenu>

                    {endsRun || failed ? (
                        <span className="flex items-center gap-1.5 px-1 text-[0.6875rem] text-ink-mute">
                            <time dateTime={message.created_at}>
                                {time(new Date(message.created_at))}
                            </time>
                            {mine ? <DeliveryMark delivery={message.delivery} /> : null}
                            {failed ? (
                                <button
                                    type="button"
                                    onClick={() => onRetry(message.id)}
                                    className="rounded-sm font-medium whitespace-nowrap text-bad underline underline-offset-2 hover:no-underline active:opacity-70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
                                >
                                    {deliveryLabel('failed')} — retry
                                </button>
                            ) : null}
                        </span>
                    ) : null}
                </div>

                {settled ? <MessageMenu actions={actions} /> : null}
            </div>
        </>
    );
}

/**
 * Revealed on hover, in a slot the layout always holds open — a control that
 * appears and reflows the bubble under the cursor is worse than no control.
 *
 * The same actions are also on the bubble's right-click menu. This one exists
 * because right-click is not discoverable and does not exist on touch.
 */
function MessageMenu({ actions }: { actions: Action[] }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label="Message actions"
                className={cn(
                    'grid size-7 shrink-0 place-items-center rounded-full text-ink-mute',
                    'opacity-0 transition-opacity duration-(--dur-micro) ease-out',
                    'group-hover/msg:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100',
                    'hover:bg-surface-2 hover:text-ink',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                )}
            >
                <ChevronDown className="size-4" aria-hidden />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-52">
                {actions.map((a) => (
                    <DropdownMenuItem
                        key={a.key}
                        variant={a.destructive ? 'destructive' : 'default'}
                        onSelect={a.run}
                    >
                        <a.icon aria-hidden />
                        {a.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DayDivider({ label }: { label: string }) {
    return (
        <div className="my-4 flex items-center gap-3" role="separator" aria-label={label}>
            <span className="h-px flex-1 bg-line" />
            <span className="text-[0.6875rem] font-medium tracking-[0.02em] text-ink-mute uppercase">
                {label}
            </span>
            <span className="h-px flex-1 bg-line" />
        </div>
    );
}

function TypingIndicator({ names }: { names: string[] }) {
    const label =
        names.length === 1
            ? `${names[0]} is typing`
            : names.length === 2
              ? `${names[0]} and ${names[1]} are typing`
              : `${names[0]} and ${names.length - 1} others are typing`;

    return (
        <div className="mt-3 flex items-end gap-2">
            <span className="w-8 shrink-0" />
            <div
                className="flex items-center gap-2 rounded-2xl rounded-es-md border border-line bg-surface px-3.5 py-2.5"
                aria-live="polite"
            >
                <span className="flex items-center gap-1" aria-hidden>
                    {[0, 1, 2].map((i) => (
                        <span
                            key={i}
                            className="typing-dot size-1.5 rounded-full bg-ink-mute"
                            style={{ animationDelay: `${i * 160}ms` }}
                        />
                    ))}
                </span>
                <span className="text-[0.75rem] text-ink-mute">{label}</span>
            </div>
        </div>
    );
}

/**
 * Shown when no conversation is open — the third pane is never blank, and it is
 * exactly where someone with no chats yet is standing, so it carries both the
 * way in and the handle to hand out.
 */
export function ThreadEmpty({ currentUser }: { currentUser: Participant }) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-6 px-8 text-center">
            <BrandLockup tagline className="text-ink" />

            <p className="max-w-xs text-[0.8125rem] text-ink-mute">
                Pick a chat on the left to read it here, or start one with someone’s username.
            </p>

            <ComposeMenu />

            {currentUser.username ? (
                <p className="text-[0.8125rem] text-ink-mute">
                    You are <span className="font-medium text-ink">@{currentUser.username}</span> — share
                    it so people can find you.
                </p>
            ) : null}
        </div>
    );
}

export { buildThread, conversationTitle, counterpart };
