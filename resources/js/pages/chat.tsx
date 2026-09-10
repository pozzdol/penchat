import { Composer } from '@/components/chat/composer';
import { ConversationList } from '@/components/chat/conversation-list';
import { DetailsPanel } from '@/components/chat/details-panel';
import { FloatingNav, Rail } from '@/components/chat/rail';
import { Thread, ThreadEmpty } from '@/components/chat/thread';
import { ThreadHeader } from '@/components/chat/thread-header';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useRealtime } from '@/hooks/use-realtime';
import { buildThread, conversationTitle } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { ChatPageProps, Message } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** A message typed here but not yet acknowledged by the server. */
interface Unsent {
    /** Prefixed, so it can never collide with a real ULID. */
    id: string;
    conversation_id: string;
    body: string;
    created_at: string;
    failed: boolean;
}

/**
 * The Workbench shell: rail · list · thread. Three fixed panes, one scroll
 * region each, and the document itself never scrolls.
 *
 * The server owns the thread. Local state holds only what the server has not
 * confirmed yet — keeping a second copy of the conversation here is how the two
 * drift apart. A send posts, then asks Inertia to refresh just `messages` and
 * `conversations`, which is what keeps the sidebar's order, last message and
 * ticks correct without recomputing any of it in the browser.
 */
export default function Chat({
    current_user,
    conversations,
    active_conversation_id,
    messages,
}: ChatPageProps) {
    /**
     * Whether a conversation was actually *asked for*, as opposed to the one
     * the server picks to fill the third pane at `/`.
     *
     * The two are not the same and only mobile notices. On a phone there is
     * one pane, so "the server chose a conversation for you" and "you opened
     * a conversation" have to be told apart — otherwise the list is
     * unreachable at `/` and Back is a no-op, because going home simply
     * re-selects the same chat. On md and up both panes are visible and this
     * makes no difference at all.
     */
    const inRoom = usePage().url.startsWith('/c/');

    const [unsent, setUnsent] = useState<Unsent[]>([]);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [editing, setEditing] = useState<{ id: string; body: string } | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const keyRef = useRef(0);

    const active = useMemo(
        () => conversations.find((c) => c.id === active_conversation_id) ?? null,
        [conversations, active_conversation_id],
    );

    /**
     * The newest message in every conversation, as this browser last saw them.
     * It is what tells the server the device has them — and it has to cover
     * every row, not just the open one, because "delivered" is about arriving
     * on the device, not about being looked at.
     */
    const newestSeen = useMemo(
        () => conversations.map((c) => c.last_message?.id ?? '').join(),
        [conversations],
    );

    const { online, incoming, forget, typing, onTyping } = useRealtime(
        current_user.id,
        current_user.name,
        active_conversation_id,
        newestSeen,
    );

    /**
     * Once the server prop carries a message, the socket's copy of it is dead
     * weight — and the authoritative one, which knows this viewer's `delivery`.
     */
    useEffect(() => {
        forget(new Set(messages.map((m) => m.id)));
    }, [messages, forget]);

    const thread = useMemo<Message[]>(() => {
        const mine = unsent
            .filter((u) => u.conversation_id === active?.id)
            .map<Message>((u) => ({
                id: u.id,
                conversation_id: u.conversation_id,
                user_id: current_user.id,
                body: u.body,
                created_at: u.created_at,
                edited_at: null,
                deleted_at: null,
                attachments: [],
                delivery: u.failed ? 'failed' : 'pending',
            }));

        /* Three sources, one thread. The server's copy wins wherever both have
           a message, because only it knows whether this viewer has read it.
           Sorting by id rather than by `created_at` is deliberate: ids are
           ULIDs, so they already sort by time, and two messages written in the
           same second still land in a stable order. */
        const byId = new Map<string, Message>();
        for (const m of incoming) byId.set(m.id, { ...m, delivery: 'sent' });
        for (const m of messages) byId.set(m.id, m);

        const settled = [...byId.values()].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));

        return [...settled, ...mine];
    }, [messages, incoming, unsent, active?.id, current_user.id]);

    const items = useMemo(
        () => (active ? buildThread(thread, active.participants, new Date()) : []),
        [thread, active],
    );

    /**
     * Sends are queued and sent async, and both halves of that matter.
     *
     * Inertia's default (sync) stream is `{ maxConcurrent: 1, interruptible:
     * true }` and every new visit calls `interruptInFlight()`, so typing two
     * messages quickly cancels the first request — the server still commits it,
     * but the response never arrives and its bubble stays "pending" forever.
     * `async: true` moves them to the uninterruptible stream.
     *
     * That alone allows responses to land out of order, and each response
     * carries the whole authoritative thread, so an older one arriving last
     * would hide a newer message. Chaining the sends keeps one in flight at a
     * time, which restores the ordering the sync stream used to give us.
     */
    const queueRef = useRef<Promise<void>>(Promise.resolve());

    const post = useCallback((conversationId: string, key: string, body: string) => {
        queueRef.current = queueRef.current.then(
            () =>
                new Promise<void>((resolve) => {
                    router.post(
                        `/conversations/${conversationId}/messages`,
                        { body },
                        {
                            async: true,
                            // The sidebar changes on every send, so it comes back too.
                            only: ['messages', 'conversations'],
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => {
                                setNotice(null);
                                setUnsent((prev) => prev.filter((u) => u.id !== key));
                            },
                            onError: (errors) => {
                                // The server refuses a send for exactly two
                                // reasons it wants said out loud: too fast,
                                // or paused.
                                setNotice(errors.suspended ?? errors.body ?? null);
                                setUnsent((prev) =>
                                    prev.map((u) => (u.id === key ? { ...u, failed: true } : u)),
                                );
                            },
                            onFinish: () => resolve(),
                        },
                    );
                }),
        );
    }, []);

    const send = (body: string) => {
        if (!active) return;

        const id = `temp:${++keyRef.current}`;

        setUnsent((prev) => [
            ...prev,
            {
                id,
                conversation_id: active.id,
                body,
                created_at: new Date().toISOString(),
                failed: false,
            },
        ]);

        post(active.id, id, body);
    };

    const retry = (messageId: string) => {
        const entry = unsent.find((u) => u.id === messageId);
        if (!entry) return;

        setUnsent((prev) => prev.map((u) => (u.id === messageId ? { ...u, failed: false } : u)));
        post(entry.conversation_id, entry.id, entry.body);
    };

    /**
     * Editing and deleting are one-at-a-time, deliberate acts, so they go out
     * as ordinary requests rather than through the send queue — and they ask
     * for the same two props back, because either can change the sidebar's
     * preview as well as the thread.
     */
    const refresh = () => ({
        only: ['messages', 'conversations'],
        preserveScroll: true,
        preserveState: true,
    });

    const saveEdit = (body: string) => {
        if (!editing) return;

        router.patch(`/messages/${editing.id}`, { body }, refresh());
        setEditing(null);
    };

    const remove = (message: Message, everyone: boolean) => {
        // Rewriting a message that is about to disappear helps nobody.
        if (editing?.id === message.id) setEditing(null);

        router.delete(
            everyone ? `/messages/${message.id}` : `/messages/${message.id}/mine`,
            refresh(),
        );
    };

    /**
     * Mark read when the open conversation has something unread and this window
     * is actually in front. `unread_count` is the signal, so the read pointer
     * never has to travel to the browser: once the PATCH lands the count comes
     * back zero and this stops firing on its own.
     */
    const sentRef = useRef<string | null>(null);

    /**
     * The newest id the *server* knows about. A `temp:` id is not a message
     * anyone can point a read pointer at, and a socket arrival that has not
     * been confirmed yet would move the pointer past something the reader has
     * not been shown.
     */
    const lastSettled = useMemo(
        () => messages.at(-1)?.id ?? null,
        [messages],
    );

    const markRead = useCallback(() => {
        const last = lastSettled;

        if (!active || active.unread_count === 0 || !last || !document.hasFocus()) {
            return;
        }

        const stamp = `${active.id}:${last}`;
        if (sentRef.current === stamp) return;
        sentRef.current = stamp;

        router.patch(
            `/conversations/${active.id}/read`,
            { message_id: last },
            // Async for the same reason as a send: a read receipt must never
            // cancel a message that is still on its way out.
            { async: true, only: ['conversations'], preserveScroll: true, preserveState: true },
        );
    }, [active, lastSettled]);

    useEffect(() => {
        markRead();

        window.addEventListener('focus', markRead);

        return () => window.removeEventListener('focus', markRead);
    }, [markRead]);

    /**
     * The server sends `online: false` for everyone but you — it has no way to
     * know, and AGENTS.md forbids persisting it. The presence roster is the
     * only source of truth, so it is applied once here rather than drilled
     * into the three panes that draw a dot.
     */
    const live = useMemo(
        () =>
            conversations.map((c) => ({
                ...c,
                participants: c.participants.map((p) => ({
                    ...p,
                    online: p.id === current_user.id || online.has(p.id),
                })),
            })),
        [conversations, online, current_user.id],
    );

    const liveActive = useMemo(
        () => live.find((c) => c.id === active_conversation_id) ?? null,
        [live, active_conversation_id],
    );

    const select = (id: string) => {
        setDetailsOpen(false);
        setEditing(null);

        if (id === active_conversation_id) return;

        router.get(`/c/${id}`, {}, { preserveState: true });
    };

    return (
        <TooltipProvider delayDuration={600}>
            <Head title={active ? conversationTitle(active, current_user.id) : 'Chats'} />

            <div className="flex h-dvh w-full overflow-hidden bg-page text-ink">
                {/* A 56px column is a sixth of a phone. Below md the rail is
                    not narrowed, it is moved — see FloatingNav. */}
                <Rail active="chats" className="max-md:hidden" />

                {/* Below md exactly one of these two is mounted-visible: the
                    list, or the thread that replaced it. */}
                <ConversationList
                    conversations={live}
                    currentUser={current_user}
                    activeId={active_conversation_id}
                    onSelect={select}
                    className={cn(inRoom && 'max-md:hidden')}
                />

                <main
                    className={cn(
                        'flex min-w-0 flex-1 flex-col bg-page',
                        !inRoom && 'max-md:hidden',
                    )}
                >
                    {liveActive ? (
                        <>
                            <ThreadHeader
                                conversation={liveActive}
                                currentUser={current_user}
                                onBack={() => router.get('/')}
                                onToggleDetails={() => setDetailsOpen((v) => !v)}
                                detailsOpen={detailsOpen}
                            />
                            <Thread
                                conversation={liveActive}
                                items={items}
                                currentUser={current_user}
                                typing={typing}
                                onRetry={retry}
                                onEdit={(m) => setEditing({ id: m.id, body: m.body ?? '' })}
                                onDelete={remove}
                            />
                            <Composer
                                title={conversationTitle(liveActive, current_user.id)}
                                editing={editing}
                                notice={notice}
                                onSend={send}
                                onEdit={saveEdit}
                                onCancelEdit={() => setEditing(null)}
                                onTyping={onTyping}
                            />
                        </>
                    ) : (
                        <ThreadEmpty currentUser={current_user} />
                    )}
                </main>

                {/* Only over the list. A conversation gets the whole screen,
                    which is the point of the change. */}
                {inRoom ? null : <FloatingNav active="chats" />}

                {liveActive && detailsOpen ? (
                    <DetailsPanel
                        conversation={liveActive}
                        currentUser={current_user}
                        onClose={() => setDetailsOpen(false)}
                    />
                ) : null}
            </div>
        </TooltipProvider>
    );
}
