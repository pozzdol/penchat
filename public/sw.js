/*
 * The service worker. Its whole job is notifications — there is no offline
 * cache here and no route interception, because a chat app with nothing to
 * say offline gains nothing from serving a stale shell.
 *
 * Served from `public/` and deliberately not built by Vite. A worker's scope
 * is the directory it is served from, so it has to sit at the origin root
 * under a stable name; Vite would hash the filename and the registration
 * would point at a file that no longer exists after every deploy.
 *
 * Plain ES5-ish JavaScript with no imports for the same reason: nothing
 * transpiles this file.
 */

/* A new worker takes over immediately rather than waiting for every tab to
   close. Without these two, a notification format change would sit dormant
   on a phone that never fully closes the app. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const FALLBACK = {
    title: 'PenChat',
    body: 'New message',
    url: '/',
};

/**
 * Whether a window is already showing this conversation, focused, right now.
 *
 * This is the entire reason the client decides and not the server: presence
 * is never persisted (AGENTS.md § transport routing), so the server genuinely
 * cannot know. The worker can — it can see the open windows and their URLs.
 *
 * Narrow on purpose. Only a *focused* window on *this* conversation suppresses
 * the notification. Suppressing whenever any window exists would swallow
 * notifications for someone reading a different chat, and it would also trip
 * the browsers' user-visible-push rules: Chrome waives the "you must show
 * something" requirement precisely when the app already has a visible window,
 * and shows its own "site updated in the background" notice otherwise.
 */
async function alreadyWatching(url) {
    const windows = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });

    return windows.some((client) => {
        if (!client.focused) return false;

        try {
            return new URL(client.url).pathname === url;
        } catch {
            return false;
        }
    });
}

self.addEventListener('push', (event) => {
    /* A push with no readable payload still has to show something, or the
       browser shows its own generic notice in our place. */
    let payload = FALLBACK;

    try {
        payload = { ...FALLBACK, ...(event.data ? event.data.json() : {}) };
    } catch {
        // Keep the fallback.
    }

    event.waitUntil(
        (async () => {
            if (await alreadyWatching(payload.url)) return;

            await self.registration.showNotification(payload.title, {
                body: payload.body,
                icon: '/image/icons/icon-192.png',
                badge: '/image/icons/badge-96.png',
                /* One notification per conversation. Five messages from the
                   same room replace each other instead of stacking into five
                   separate rows the reader has to dismiss one by one. */
                tag: payload.conversation_id || 'penchat',
                renotify: true,
                timestamp: Date.now(),
                data: { url: payload.url },
            });
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            /* Reuse a window if there is one. Opening a second copy of a chat
               app is never what the person tapping the notification wanted. */
            for (const client of windows) {
                if ('focus' in client) {
                    await client.focus();
                    if ('navigate' in client) await client.navigate(url);

                    return;
                }
            }

            await self.clients.openWindow(url);
        })(),
    );
});
