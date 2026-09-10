import type { Message } from '@/types';
import { router } from '@inertiajs/react';
import { useEcho, usePresenceChannel, useSocketId } from '@laravel/echo-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/**
 * Everything the socket does, in one file, so the subscription lifecycle is
 * readable in one place.
 *
 * `useEcho` and `usePresenceChannel` unsubscribe on unmount and when the
 * channel name changes. That is why they are here rather than hand-rolled
 * `useEffect`s: leaking a subscription on conversation switch is the duplicate
 * message bug AGENTS.md warns about, and this makes it structurally impossible
 * rather than a thing to remember.
 */

/** Long enough to collapse a burst, short enough not to feel like lag. */
const REFRESH_DEBOUNCE_MS = 250;

/** Matches the server's 2–3s guidance; a whisper is cheap but not free. */
const TYPING_THROTTLE_MS = 2000;

/** How long a "typing" claim is believed without being renewed. */
const TYPING_TTL_MS = 4000;

/**
 * Widened on purpose. With no conversation open the hooks park on a public
 * name, and the default generic pins this to `'private'` — which is what makes
 * the parked branch a type error rather than a silent doomed subscription.
 */
type Visibility = 'private' | 'public';

interface TypingWhisper {
    id: string;
    name: string;
}

/**
 * Tell the server which socket this browser is, so `->toOthers()` can leave
 * the sender out of their own broadcast.
 *
 * Inertia builds its requests with its own axios instance, so Echo's automatic
 * header never reaches them. The documented seam is the `before` event, whose
 * visit object is mutable.
 */
function useSocketHeader(): void {
    const socketId = useSocketId();

    useEffect(() => {
        if (!socketId) return;

        return router.on('before', (event) => {
            event.detail.visit.headers['X-Socket-Id'] = socketId;
        });
    }, [socketId]);
}

/**
 * Refreshed when the server says something about a conversation changed — for
 * any conversation, including the ones not on screen.
 *
 * The signal carries no content on purpose: unread counts, ordering and the
 * preview are all per-viewer since Phase 1c/1d, so there is nothing the server
 * could send that would be true for everyone on the channel.
 *
 * `messages` comes back too, not just `conversations`. `delivery` is computed
 * per viewer from everyone else's read pointers, so when a reader catches up
 * it is the *sender's* bubbles that have to change — asking only for the
 * sidebar would turn its tick green while the thread above it still showed a
 * single grey one. `reload` is already partial, scroll-preserving and async.
 */
function useConversationRefresh(currentUserId: string): void {
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEcho(`user.${currentUserId}`, '.conversation.touched', () => {
        // A burst — someone pasting three messages — is one refresh, not three.
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            router.reload({ only: ['conversations', 'messages'] });
        }, REFRESH_DEBOUNCE_MS);
    });

    useEffect(() => () => clearTimeout(timer.current), []);
}

/**
 * Tell the server everything on screen has arrived — the second grey tick for
 * whoever sent it.
 *
 * Once on mount, then whenever the socket says something changed. The server
 * only answers when a pointer actually moves, which is what keeps two clients
 * from acking each other forever.
 */
function useDeliveryAck(dependency: unknown): void {
    useEffect(() => {
        router.post('/delivered', {}, {
            // Nothing needs to come back: the pointers this moves belong to
            // other people's ticks, and they hear about it over the socket.
            only: [],
            preserveScroll: true,
            preserveState: true,
            async: true,
        });
    }, [dependency]);
}

/**
 * Who is signed in right now. Never persisted — when the socket goes, so does
 * the dot (AGENTS.md § transport routing).
 */
function useOnlineRoster(): Set<string> {
    const [online, setOnline] = useState<Set<string>>(new Set());
    const { channel } = usePresenceChannel('online');

    useEffect(() => {
        const presence = channel();
        if (!presence) return;

        const add = (user: { id: string }) =>
            setOnline((prev) => new Set(prev).add(user.id));

        const drop = (user: { id: string }) =>
            setOnline((prev) => {
                const next = new Set(prev);
                next.delete(user.id);
                return next;
            });

        presence
            .here((users: { id: string }[]) => setOnline(new Set(users.map((u) => u.id))))
            .joining(add)
            .leaving(drop);

        // `here`/`joining`/`leaving` are raw Pusher handlers, not Echo
        // listeners, so `useEcho`'s own cleanup does not touch them. They die
        // with the channel object today, but only because this effect happens
        // to run once — dropping the roster on the way out keeps a stale one
        // from surviving a re-run.
        return () => setOnline(new Set());
    }, [channel]);

    return online;
}

/**
 * New messages arriving in the conversation that is open.
 *
 * Only new ones: an edit or a tombstone comes as `ConversationTouched` and is
 * resolved by a reload, because a channel payload is identical for everyone
 * while visibility is not (see `MessageSent`).
 *
 * Still an upsert by id rather than an append — a sender can receive its own
 * message through both the HTTP response and the socket, which is the
 * duplicate AGENTS.md warns about.
 */
function useIncomingMessages(conversationId: string | null): {
    incoming: Message[];
    forget: (ids: Set<string>) => void;
} {
    const [incoming, setIncoming] = useState<Message[]>([]);

    /*
     * With no conversation open there is nothing to listen to, but a hook
     * cannot be skipped. Parking on a *public* name is the cheap way out: a
     * private one would send `/broadcasting/auth` a subscription request that
     * is designed to be refused, and log a 403 on every such page load.
     */
    useEcho<{ message: Message }, 'reverb', Visibility>(
        conversationId ? `conversation.${conversationId}` : 'idle',
        '.message.sent',
        ({ message }) => {
            if (message.conversation_id !== conversationId) return;

            setIncoming((prev) => {
                const at = prev.findIndex((m) => m.id === message.id);
                if (at === -1) return [...prev, message];

                const next = [...prev];
                next[at] = message;
                return next;
            });
        },
        [conversationId],
        conversationId ? 'private' : 'public',
    );

    // Switching conversations drops the other thread's arrivals on the floor.
    useEffect(() => setIncoming([]), [conversationId]);

    const forget = useCallback((ids: Set<string>) => {
        setIncoming((prev) => (prev.some((m) => ids.has(m.id)) ? prev.filter((m) => !ids.has(m.id)) : prev));
    }, []);

    return { incoming, forget };
}

/**
 * Typing, in both directions. Never persisted and never queued.
 *
 * The remote indicator clears on a timer rather than only on an explicit stop,
 * because the explicit stop is exactly what a closed laptop does not send.
 */
function useTyping(conversationId: string | null, currentUserId: string) {
    const [typing, setTyping] = useState<Record<string, { name: string; at: number }>>({});
    const lastSent = useRef(0);

    /*
     * `.client-typing`, not `.typing`. A whisper is sent as `client-<name>`
     * and `listenForWhisper('typing')` is a thin wrapper over
     * `listen('.client-typing')` — subscribing to the bare name compiles,
     * connects, and then simply never fires.
     */
    const { channel } = useEcho<TypingWhisper, 'reverb', Visibility>(
        conversationId ? `conversation.${conversationId}` : 'idle',
        '.client-typing',
        (whisper) => {
            if (whisper.id === currentUserId) return;

            setTyping((prev) => ({ ...prev, [whisper.id]: { name: whisper.name, at: Date.now() } }));
        },
        [conversationId, currentUserId],
        conversationId ? 'private' : 'public',
    );

    useEffect(() => setTyping({}), [conversationId]);

    // Expire stale claims. One interval for everyone rather than a timer each.
    useEffect(() => {
        const tick = setInterval(() => {
            setTyping((prev) => {
                const fresh = Object.fromEntries(
                    Object.entries(prev).filter(([, v]) => Date.now() - v.at < TYPING_TTL_MS),
                );

                return Object.keys(fresh).length === Object.keys(prev).length ? prev : fresh;
            });
        }, 1000);

        return () => clearInterval(tick);
    }, []);

    const notify = useCallback(
        (name: string) => {
            const now = Date.now();
            if (now - lastSent.current < TYPING_THROTTLE_MS) return;

            lastSent.current = now;
            channel()?.whisper('typing', { id: currentUserId, name });
        },
        [channel, currentUserId],
    );

    const names = useMemo(() => Object.values(typing).map((t) => t.name), [typing]);

    return { names, notify };
}

export function useRealtime(
    currentUserId: string,
    currentUserName: string,
    conversationId: string | null,
    /**
     * Changes whenever any conversation gains a message. Acking is keyed on
     * this rather than on the open thread, because the tick that has to move
     * is often in a conversation the reader is not looking at — which is the
     * whole point of a second grey tick.
     */
    newestSeen: string,
) {
    useSocketHeader();
    useConversationRefresh(currentUserId);

    const online = useOnlineRoster();
    const { incoming, forget } = useIncomingMessages(conversationId);
    const { names, notify } = useTyping(conversationId, currentUserId);

    // Socket arrivals count as seen too, before any reload confirms them.
    useDeliveryAck(`${newestSeen}|${incoming.at(-1)?.id ?? ''}`);

    const onTyping = useCallback(() => notify(currentUserName), [notify, currentUserName]);

    return { online, incoming, forget, typing: names, onTyping };
}
