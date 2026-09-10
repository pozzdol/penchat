import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ComponentProps, ReactNode } from 'react';

/**
 * Label above, helper below. The helper slot keeps a line of height even when
 * empty so an error appearing never shifts the form under the pointer.
 */
export function Field({
    label,
    htmlFor,
    error,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={htmlFor} className="text-[0.8125rem] font-medium">
                {label}
            </label>
            {children}
            <p
                id={`${htmlFor}-help`}
                role={error ? 'alert' : undefined}
                className={cn('min-h-[1lh] text-[0.8125rem]', error ? 'text-bad' : 'text-ink-mute')}
            >
                {error ?? hint ?? ''}
            </p>
        </div>
    );
}

/**
 * A username field. The @ is drawn in the box rather than typed, so the value
 * never carries one, and input is folded to lowercase as it is typed — the
 * server stores handles lowercase, and a field that silently disagrees with
 * what it will save is a field that lies.
 */
export function UsernameInput({
    value,
    onValueChange,
    className,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange'> & {
    value: string;
    onValueChange: (value: string) => void;
}) {
    return (
        <div className="relative">
            <span
                aria-hidden
                className="pointer-events-none absolute start-3.5 top-1/2 -translate-y-1/2 text-ink-mute"
            >
                @
            </span>
            <Input
                {...props}
                value={value}
                onChange={(e) => onValueChange(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
                autoComplete="username"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                maxLength={20}
                className={cn('h-11 ps-7', className)}
            />
        </div>
    );
}
