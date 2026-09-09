import { DeliveryMark, deliveryLabel } from '@/components/chat/delivery-mark';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { BrandLockup } from '@/components/chat/brand';
import { buildThread, conversationTitle, counterpart, time, type ThreadItem } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { Conversation, Participant } from '@/types';
import { useEffect, useRef } from 'react';

interface Props {
    conversation: Conversation;
    items: ThreadItem[];
    currentUser: Participant;
    /** Names of people currently composing. Never persisted — a whisper only. */
    typing: string[];
    onRetry: (messageId: number) => void;
}

export function Thread({ conversation, items, currentUser, typing, onRetry }: Props) {
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
                    />
                ))}

                {typing.length > 0 ? <TypingIndicator names={typing} /> : null}

                <div ref={endRef} />
            </div>
        </div>
    );
}

function MessageRow({
    item,
    conversation,
    currentUser,
    onRetry,
}: {
    item: ThreadItem;
    conversation: Conversation;
    currentUser: Participant;
    onRetry: (messageId: number) => void;
}) {
    const { message, author, startsRun, endsRun, dayBreak } = item;
    const mine = message.user_id === currentUser.id;
    const isGroup = conversation.type === 'group';
    const failed = message.delivery === 'failed';

    return (
        <>
            {dayBreak ? <DayDivider label={dayBreak} /> : null}

            <div
                className={cn(
                    'flex items-end gap-2',
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
                        )}
                    >
                        <p className="[overflow-wrap:anywhere] whitespace-pre-wrap">
                            {message.body}
                        </p>
                    </div>

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
            </div>
        </>
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

/** Shown when no conversation is open — the third pane is never blank. */
export function ThreadEmpty() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-6 px-8 text-center">
            <BrandLockup tagline className="text-ink" />
            <p className="max-w-xs text-[0.8125rem] text-ink-mute">
                Pick a chat on the left to read it here, or search for someone to start a new one.
            </p>
        </div>
    );
}

export { buildThread, conversationTitle, counterpart };
