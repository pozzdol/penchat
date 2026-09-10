import { Composer } from '@/components/chat/composer';
import { ConversationList } from '@/components/chat/conversation-list';
import { Rail } from '@/components/chat/rail';
import { Thread, ThreadEmpty } from '@/components/chat/thread';
import { ThreadHeader } from '@/components/chat/thread-header';
import { TooltipProvider } from '@/components/ui/tooltip';
import { buildThread, conversationTitle } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { ChatPageProps, Message } from '@/types';
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
    const [unsent, setUnsent] = useState<Unsent[]>([]);
    const keyRef = useRef(0);

    const active = useMemo(
        () => conversations.find((c) => c.id === active_conversation_id) ?? null,
        [conversations, active_conversation_id],
    );

    const thread = useMemo<Message[]>(() => {
        const mine = unsent
            .filter((u) => u.conversation_id === active?.id)
            .map<Message>((u) => ({
                id: u.id,
                conversation_id: u.conversation_id,
                user_id: current_user.id,
                body: u.body,
                created_at: u.created_at,
                attachments: [],
                delivery: u.failed ? 'failed' : 'pending',
            }));

        return [...messages, ...mine];
    }, [messages, unsent, active?.id, current_user.id]);

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
                            onSuccess: () =>
                                setUnsent((prev) => prev.filter((u) => u.id !== key)),
                            onError: () =>
                                setUnsent((prev) =>
                                    prev.map((u) => (u.id === key ? { ...u, failed: true } : u)),
                                ),
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
     * Mark read when the open conversation has something unread and this window
     * is actually in front. `unread_count` is the signal, so the read pointer
     * never has to travel to the browser: once the PATCH lands the count comes
     * back zero and this stops firing on its own.
     */
    const sentRef = useRef<string | null>(null);

    const markRead = useCallback(() => {
        const last = messages.at(-1)?.id;

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
    }, [active, messages]);

    useEffect(() => {
        markRead();

        window.addEventListener('focus', markRead);

        return () => window.removeEventListener('focus', markRead);
    }, [markRead]);

    const select = (id: string) => {
        if (id === active_conversation_id) return;

        router.get(`/c/${id}`, {}, { preserveState: true });
    };

    return (
        <TooltipProvider delayDuration={600}>
            <Head title={active ? conversationTitle(active, current_user.id) : 'Chats'} />

            <div className="flex h-dvh w-full overflow-hidden bg-page text-ink">
                <Rail active="chats" />

                {/* Below md exactly one of these two is mounted-visible: the
                    list, or the thread that replaced it. */}
                <ConversationList
                    conversations={conversations}
                    currentUser={current_user}
                    activeId={active_conversation_id}
                    onSelect={select}
                    className={cn(active && 'max-md:hidden')}
                />

                <main
                    className={cn(
                        'flex min-w-0 flex-1 flex-col bg-page',
                        !active && 'max-md:hidden',
                    )}
                >
                    {active ? (
                        <>
                            <ThreadHeader
                                conversation={active}
                                currentUser={current_user}
                                onBack={() => router.get('/')}
                            />
                            <Thread
                                conversation={active}
                                items={items}
                                currentUser={current_user}
                                typing={[]}
                                onRetry={retry}
                            />
                            <Composer
                                title={conversationTitle(active, current_user.id)}
                                onSend={send}
                                onTyping={() => {
                                    /* whisper goes here once Reverb is wired */
                                }}
                            />
                        </>
                    ) : (
                        <ThreadEmpty currentUser={current_user} />
                    )}
                </main>
            </div>
        </TooltipProvider>
    );
}
