import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Web Push, from the browser's side. Registration, permission, and keeping
 * the server's idea of this device in step with the browser's.
 *
 * Deliberately separate from `use-realtime.ts` and touching no Echo hook. A
 * push subscription outlives the page — that is the whole point of it — so it
 * must not be tangled with subscriptions whose correctness depends on being
 * torn down at unmount.
 */

/** Where the worker lives. Root scope, so it sees every window of the app. */
const WORKER_URL = '/sw.js';

/** One row per device, addressed by endpoint — see PushSubscriptionController. */
const SUBSCRIPTIONS_URL = '/push/subscriptions';

export type PushState =
    /** No service worker, no PushManager, or no VAPID key configured. */
    | 'unsupported'
    /** Supported, but this browser has been told no and will not be asked again. */
    | 'denied'
    /** Supported and available, not subscribed. */
    | 'off'
    /** Subscribed; the server knows about this device. */
    | 'on'
    /** A subscribe or unsubscribe is in flight. */
    | 'busy';

/**
 * `applicationServerKey` wants raw bytes; the key travels as base64url
 * because that is what fits in JSON and an env file.
 *
 * Backed by an explicitly allocated `ArrayBuffer` rather than
 * `Uint8Array.from`, whose type is widened to `ArrayBufferLike` — which
 * `BufferSource` does not accept, because a `SharedArrayBuffer` cannot be
 * handed to the push manager.
 */
function decodeKey(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * Laravel's CSRF cookie. Inertia's axios sends this header on its own, but
 * these calls are plain `fetch` — a subscription change is not a navigation,
 * and routing it through Inertia would re-render the page's props every time
 * someone toggled a bell.
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post(url: string, method: 'POST' | 'DELETE', body: unknown): Promise<void> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    if (!response.ok) throw new Error(`${method} ${url} failed: ${response.status}`);
}

export function usePush() {
    const { vapidPublicKey } = usePage<SharedProps>().props;
    const [state, setState] = useState<PushState>('unsupported');

    const available =
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window &&
        Boolean(vapidPublicKey);

    /**
     * Register the worker and report what this browser currently thinks.
     *
     * The re-POST of an existing subscription is not redundant. A browser can
     * hand back a subscription the server has since pruned — a push service
     * reported it gone, or the row went with a deleted account — and without
     * this the bell would show "on" while nothing could ever arrive. Upserting
     * on every load makes the two agree, cheaply, because the endpoint is the
     * key.
     */
    useEffect(() => {
        if (!available) {
            setState('unsupported');

            return;
        }

        if (Notification.permission === 'denied') {
            setState('denied');

            return;
        }

        let cancelled = false;

        (async () => {
            try {
                const registration = await navigator.serviceWorker.register(WORKER_URL, {
                    scope: '/',
                });
                const subscription = await registration.pushManager.getSubscription();

                if (cancelled) return;

                if (!subscription) {
                    setState('off');

                    return;
                }

                await post(SUBSCRIPTIONS_URL, 'POST', subscription.toJSON());

                if (!cancelled) setState('on');
            } catch (error) {
                console.error('Push registration failed.', error);

                if (!cancelled) setState('off');
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [available]);

    const enable = useCallback(async () => {
        if (!available) return;

        setState('busy');

        try {
            /* Asked only here, behind a click. A permission prompt on page
               load is the pattern browsers now penalise and readers reflexively
               dismiss — and a dismissal is permanent. */
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                setState(permission === 'denied' ? 'denied' : 'off');

                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                // Required, and required to be true: a push that shows nothing
                // is one the browsers will eventually stop delivering.
                userVisibleOnly: true,
                applicationServerKey: decodeKey(vapidPublicKey!),
            });

            await post(SUBSCRIPTIONS_URL, 'POST', subscription.toJSON());
            setState('on');
        } catch (error) {
            console.error('Could not enable notifications.', error);
            setState('off');
        }
    }, [available, vapidPublicKey]);

    const disable = useCallback(async () => {
        if (!available) return;

        setState('busy');

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                /* Server first. If the browser drops the subscription and the
                   delete then fails, the row is orphaned and unreachable —
                   nothing can ever tell the server that endpoint is dead
                   except a push service rejecting it later. */
                await post(SUBSCRIPTIONS_URL, 'DELETE', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }

            setState('off');
        } catch (error) {
            console.error('Could not disable notifications.', error);
            setState('on');
        }
    }, [available]);

    return { state, enable, disable };
}
