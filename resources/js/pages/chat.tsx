import { Composer } from '@/components/chat/composer';
import { ConversationList } from '@/components/chat/conversation-list';
import { DetailsPanel } from '@/components/chat/details-panel';
import { FloatingNav, Rail } from '@/components/chat/rail';
import { Thread, ThreadEmpty } from '@/components/chat/thread';
import { ThreadHeader } from '@/components/chat/thread-header';
import { SettingsPane } from '@/components/settings/settings-pane';
import { TooltipProvider } from '@/components/ui/tooltip';
import { usePush } from '@/hooks/use-push';
import { useRealtime } from '@/hooks/use-realtime';
import { buildThread, conversationTitle } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { ChatPageProps, Message, MessageQuote } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** A message typed here but not yet acknowledged by the server. */
interface Unsent {
    /** Prefixed, so it can never collide with a real ULID. */
    id: string;
    conversation_id: string;
    body: string;
    created_at: string;
    failed: boolean;
    /** Carried so the optimistic bubble shows its quote too. */
    reply_to: MessageQuote | null;
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
    account,
    conversations,
    active_conversation_id,
    messages,
    settings_open,
}: ChatPageProps) {
    const [unsent, setUnsent] = useState<Unsent[]>([]);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [editing, setEditing] = useState<{ id: string; body: string } | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [replying, setReplying] = useState<{ id: string; author: string; body: string } | null>(null);
    const keyRef = useRef(0);

    const active = useMemo(
        () => conversations.find((c) => c.id === active_conversation_id) ?? null,
        [conversations, active_conversation_id],
    );

    /* Below md there is one pane, and either of these two fills it. Without
       the second term, opening Settings on a phone would leave the
       conversation list on top of it. */
    const filled = active !== null || settings_open;

    /* Called here rather than inside the settings pane, and that placement is
       load-bearing: this is what registers the service worker and re-syncs
       this device's subscription with the server. Mounted only on Settings,
       it would run for people who never open Settings — which is everyone,
       most days. */
    const push = usePush();

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
                reply_to: u.reply_to,
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

    const post = useCallback((conversationId: string, key: string, body: string, replyTo: string | null) => {
        queueRef.current = queueRef.current.then(
            () =>
                new Promise<void>((resolve) => {
                    router.post(
                        `/conversations/${conversationId}/messages`,
                        { body, reply_to_message_id: replyTo },
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
        const quote = replying
            ? { id: replying.id, author: replying.author, body: replying.body, deleted: false }
            : null;

        setUnsent((prev) => [
            ...prev,
            {
                id,
                conversation_id: active.id,
                body,
                created_at: new Date().toISOString(),
                failed: false,
                reply_to: quote,
            },
        ]);

        post(active.id, id, body, replying?.id ?? null);
        setReplying(null);
    };

    const retry = (messageId: string) => {
        const entry = unsent.find((u) => u.id === messageId);
        if (!entry) return;

        setUnsent((prev) => prev.map((u) => (u.id === messageId ? { ...u, failed: false } : u)));
        post(entry.conversation_id, entry.id, entry.body, entry.reply_to?.id ?? null);
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

    const reply = (message: Message) => {
        // One at a time: the composer cannot be rewriting your words and
        // answering someone else's at once.
        setEditing(null);
        setReplying({
            id: message.id,
            author:
                message.user_id === current_user.id
                    ? 'yourself'
                    : (active?.participants.find((p) => p.id === message.user_id)?.name ?? 'Unknown'),
            body: message.body ?? 'Attachment',
        });
    };

    const saveEdit = (body: string) => {
        if (!editing) return;

        router.patch(`/messages/${editing.id}`, { body }, refresh());
        setEditing(null);
    };

    const remove = (message: Message, everyone: boolean) => {
        // Rewriting or quoting a message that is about to disappear helps
        // nobody.
        if (editing?.id === message.id) setEditing(null);
        if (replying?.id === message.id) setReplying(null);

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
        setReplying(null);

        if (id === active_conversation_id) return;

        router.get(`/c/${id}`, {}, { preserveState: true });
    };

    return (
        <TooltipProvider delayDuration={600}>
            <Head
                title={
                    settings_open
                        ? 'Settings'
                        : active
                          ? conversationTitle(active, current_user.id)
                          : 'Chats'
                }
            />

            <div className="flex h-dvh w-full overflow-hidden bg-page text-ink">
                {/* A 56px column is a sixth of a phone. Below md the rail is
                    not narrowed, it is moved — see FloatingNav. */}
                <Rail active={settings_open ? 'settings' : 'chats'} className="max-md:hidden" />

                {/* Below md exactly one of these two is mounted-visible: the
                    list, or the thread that replaced it. */}
                <ConversationList
                    conversations={live}
                    currentUser={current_user}
                    activeId={active_conversation_id}
                    onSelect={select}
                    className={cn(filled && 'max-md:hidden')}
                />

                <main
                    className={cn(
                        'flex min-w-0 flex-1 flex-col bg-page',
                        !filled && 'max-md:hidden',
                    )}
                >
                    {settings_open ? (
                        <SettingsPane currentUser={current_user} account={account} push={push} />
                    ) : liveActive ? (
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
                                onReply={reply}
                                onClose={() => router.get('/')}
                                onInfo={() => setDetailsOpen(true)}
                            />
                            <Composer
                                title={conversationTitle(liveActive, current_user.id)}
                                editing={editing}
                                notice={notice}
                                replying={replying}
                                onSend={send}
                                onEdit={saveEdit}
                                onCancelEdit={() => setEditing(null)}
                                onCancelReply={() => setReplying(null)}
                                onTyping={onTyping}
                            />
                        </>
                    ) : (
                        <ThreadEmpty currentUser={current_user} />
                    )}
                </main>

                {/* Over the list and over Settings, but never over a
                    conversation — a room gets the whole screen, which is the
                    point of moving the rail down here. On Settings it is the
                    only way back on a phone, so it has to stay. */}
                {active ? null : <FloatingNav active={settings_open ? 'settings' : 'chats'} />}

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
