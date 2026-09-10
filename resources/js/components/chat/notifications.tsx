import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { PushState } from '@/hooks/use-push';
import { cn } from '@/lib/utils';
import { Bell, BellOff, Share, X } from 'lucide-react';
import { useState } from 'react';

/**
 * The bell, presentational. State comes from `usePush` one level up so the
 * worker is registered exactly once per page rather than once per component
 * that happens to want to know about it.
 *
 * Nothing is rendered when push cannot work here — no VAPID key, no
 * PushManager, or an iPhone that has not installed the app. A control that
 * could never succeed is worse than no control, and the iOS case gets
 * {@see InstallHint} instead, which says something actionable.
 */
export function NotificationBell({
    state,
    onEnable,
    onDisable,
    className,
}: {
    state: PushState;
    onEnable: () => void;
    onDisable: () => void;
    className?: string;
}) {
    if (state === 'unsupported') return null;

    const on = state === 'on';
    const blocked = state === 'denied';
    const label = blocked
        ? 'Notifications are blocked in your browser settings'
        : on
          ? 'Turn off notifications'
          : 'Turn on notifications';

    return (
        <Tooltip>
            <TooltipTrigger
                type="button"
                disabled={blocked || state === 'busy'}
                aria-pressed={on}
                onClick={on ? onDisable : onEnable}
                className={cn(
                    'grid size-11 shrink-0 place-items-center rounded-full',
                    'transition-colors duration-(--dur-micro) ease-out',
                    'hover:bg-surface-2 hover:text-ink active:bg-surface-2',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    'disabled:pointer-events-none disabled:opacity-45',
                    on ? 'text-ink' : 'text-ink-mute',
                    className,
                )}
            >
                {on ? <Bell className="size-5" aria-hidden /> : <BellOff className="size-5" aria-hidden />}
                <span className="sr-only">{label}</span>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

const DISMISSED_KEY = 'penchat:install-hint-dismissed';

/**
 * Whether this is an iPhone or iPad that has not been installed to the home
 * screen.
 *
 * iPadOS reports itself as a Mac, hence the touch-point check — a real Mac has
 * no touch points. `navigator.standalone` is Safari's own, older answer and
 * still the reliable one on iOS; the media query covers everything else.
 */
function needsInstalling(): boolean {
    if (typeof window === 'undefined') return false;

    const ua = navigator.userAgent;
    const iOS =
        /iPhone|iPad|iPod/.test(ua) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    if (!iOS) return false;

    const installed =
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true;

    return !installed;
}

/**
 * Safari will not deliver a push to a page in a tab. Since iOS 16.4 the site
 * has to be on the home screen first, and nothing in the browser says so —
 * an iPhone user would otherwise just find no bell and conclude the feature
 * does not exist.
 *
 * Shown only where it is true and only until dismissed. `localStorage` is the
 * right home for that: it is a per-viewer convenience, worth nothing on
 * another device, and losing it costs one dismissal.
 */
export function InstallHint({ state, className }: { state: PushState; className?: string }) {
    const [dismissed, setDismissed] = useState(() => {
        try {
            return localStorage.getItem(DISMISSED_KEY) === '1';
        } catch {
            return false;
        }
    });

    if (dismissed || state !== 'unsupported' || !needsInstalling()) return null;

    const dismiss = () => {
        setDismissed(true);
        try {
            localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // A private window refusing to remember is not worth handling.
        }
    };

    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-lg bg-surface-2 py-2.5 ps-3 pe-1.5',
                className,
            )}
        >
            <Share className="mt-0.5 size-4 shrink-0 text-ink-mute" aria-hidden />
            <p className="min-w-0 flex-1 text-[0.8125rem] leading-snug text-ink-mute">
                For notifications on iPhone, tap Share and then{' '}
                <span className="text-ink-soft">Add to Home Screen</span>.
            </p>
            <button
                type="button"
                onClick={dismiss}
                className={cn(
                    'grid size-7 shrink-0 place-items-center rounded-full text-ink-mute',
                    'transition-colors duration-(--dur-micro) ease-out',
                    'hover:bg-line hover:text-ink',
                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                )}
            >
                <X className="size-4" aria-hidden />
                <span className="sr-only">Dismiss</span>
            </button>
        </div>
    );
}
