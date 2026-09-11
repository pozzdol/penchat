import { useCallback, useEffect, useState } from 'react';

/**
 * Light, dark, or whatever the machine is doing.
 *
 * The first resolution is not here — it happens in the inline script in
 * `app.blade.php`, before the first paint, because a React effect runs after
 * the browser has already painted and the reader would watch the wrong theme
 * flash to the right one on every load. This hook owns everything after that
 * moment: reading the stored choice back, changing it, and following the
 * system while the choice is `system`.
 */

export type Theme = 'system' | 'light' | 'dark';

/**
 * Shared with the inline script in `app.blade.php`, which cannot import it.
 * `tests/Feature/SettingsPageTest.php` asserts the two still agree — if they
 * drift, a chosen theme is applied by React a frame late and forgotten on the
 * next load, with nothing failing anywhere.
 */
export const THEME_STORAGE_KEY = 'penchat:theme';

/** Kept in step with the same pair in the inline script. */
const THEME_COLOR = { light: '#f2f3f6', dark: '#1a1c1e' } as const;

const DARK_QUERY = '(prefers-color-scheme: dark)';

function stored(): Theme {
    try {
        const value = localStorage.getItem(THEME_STORAGE_KEY);

        return value === 'dark' || value === 'light' ? value : 'system';
    } catch {
        // A private window refusing to remember is not worth handling.
        return 'system';
    }
}

function systemIsDark(): boolean {
    return typeof window !== 'undefined' && window.matchMedia(DARK_QUERY).matches;
}

/**
 * Stamp the resolved theme on the document, exactly the way the inline script
 * did — the class the palette answers to, and the colour the browser paints
 * its own chrome with. On a phone that second one is the status bar, so
 * leaving it behind is visible rather than academic.
 */
function apply(theme: Theme): void {
    const dark = theme === 'dark' || (theme === 'system' && systemIsDark());
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.classList.toggle('light', !dark);

    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', dark ? THEME_COLOR.dark : THEME_COLOR.light);
}

export function useTheme(): { theme: Theme; setTheme: (next: Theme) => void } {
    const [theme, set] = useState<Theme>('system');

    // The script already painted the right theme; this only catches the state
    // up with what it decided, so the control shows the real answer.
    useEffect(() => set(stored()), []);

    /* Following the system is a live promise, not a one-off reading: someone
       whose phone flips to dark at sunset expects the app to follow without
       being reopened. Only while the choice is `system` — an explicit choice
       outranks the machine. */
    useEffect(() => {
        if (theme !== 'system') return;

        const query = window.matchMedia(DARK_QUERY);
        const follow = () => apply('system');

        query.addEventListener('change', follow);

        return () => query.removeEventListener('change', follow);
    }, [theme]);

    const setTheme = useCallback((next: Theme) => {
        set(next);
        apply(next);

        try {
            localStorage.setItem(THEME_STORAGE_KEY, next);
        } catch {
            // The theme still applies for this session; it just will not
            // survive a reload, which beats refusing to change at all.
        }
    }, []);

    return { theme, setTheme };
}
