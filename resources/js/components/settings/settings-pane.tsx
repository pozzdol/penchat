import { InstallHint } from '@/components/chat/notifications';
import { PhotoCropper } from '@/components/settings/photo-cropper';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import type { PushState } from '@/hooks/use-push';
import { useTheme, type Theme } from '@/hooks/use-theme';
import { downscale } from '@/lib/image';
import { cn } from '@/lib/utils';
import type { Account, Participant } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { LogOut, Monitor, Moon, Sun } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

/**
 * Settings, grouped by *who the setting belongs to* rather than by what kind
 * of control it is.
 *
 * That grouping is the information, not decoration. The one thing people
 * reliably get wrong about a page like this is which switches follow them to
 * their other devices and which do not — so the page answers it before it is
 * asked, once per group, instead of leaving three rows that look identical
 * and behave differently.
 *
 * Built phone-first: every row is a single column that grows sideways at `md`,
 * and nothing here needs a second layout to survive 320px.
 */

/** A heading that says what the rows under it have in common. */
function Group({
    title,
    note,
    children,
}: {
    title: string;
    note: string;
    children: ReactNode;
}) {
    return (
        <section className="pt-7 first:pt-5">
            <h2 className="text-[0.9375rem] font-semibold text-ink">{title}</h2>
            <p className="mt-0.5 text-[0.8125rem] leading-snug text-ink-mute">{note}</p>

            <div className="mt-3">{children}</div>
        </section>
    );
}

/**
 * One setting. The 3.25rem floor is a thumb, not a rhythm — a row you are
 * meant to tap has to be tappable before it has to be pretty.
 */
function Row({
    label,
    children,
    stacked = false,
}: {
    label: string;
    children: ReactNode;
    stacked?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex min-h-[3.25rem] gap-2 border-b border-line py-2.5 last:border-b-0',
                /* Stacked rows put the control on its own line on a phone and
                   pull it back beside the label once there is room. A segmented
                   control next to a label is 230px of the 320px a small phone
                   actually has. */
                stacked
                    ? 'flex-col items-start justify-center md:flex-row md:items-center md:justify-between'
                    : 'items-center justify-between',
            )}
        >
            <span className="text-[0.875rem] text-ink">{label}</span>
            {children}
        </div>
    );
}

/**
 * A field you can change, edited where it sits.
 *
 * Both fields go up together on save because the server validates them
 * together — the form holds the pair and a row only decides which one you are
 * currently touching. Cancelling puts the untouched value back rather than
 * leaving a half-typed handle in state that the next save would send.
 */
function IdentityRow({
    label,
    field,
    value,
    prefix,
    form,
    editing,
    onEdit,
    onDone,
}: {
    label: string;
    field: 'name' | 'username';
    value: string;
    prefix?: string;
    form: ReturnType<typeof useForm<{ name: string; username: string }>>;
    editing: boolean;
    onEdit: () => void;
    onDone: () => void;
}) {
    const error = form.errors[field];

    const save = () => {
        form.patch('/settings/profile', {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    if (!editing) {
        return (
            <Row label={label}>
                <button
                    type="button"
                    onClick={onEdit}
                    className={cn(
                        'flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 -me-2',
                        'text-[0.875rem] text-ink-mute',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-surface-2 hover:text-ink active:bg-surface-2',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    <span className="truncate">
                        {prefix}
                        {value}
                    </span>
                    <span className="sr-only">Change {label.toLowerCase()}</span>
                    <span aria-hidden className="shrink-0 text-ink-mute">
                        ✎
                    </span>
                </button>
            </Row>
        );
    }

    return (
        <div className="border-b border-line py-3 last:border-b-0">
            <label htmlFor={`setting-${field}`} className="text-[0.875rem] text-ink">
                {label}
            </label>

            <Input
                id={`setting-${field}`}
                autoFocus
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') save();
                    if (e.key === 'Escape') onDone();
                }}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `setting-${field}-error` : undefined}
                className="mt-2 h-11"
            />

            {error ? (
                <p id={`setting-${field}-error`} role="alert" className="mt-1.5 text-[0.8125rem] text-bad">
                    {error}
                </p>
            ) : null}

            {/* Full width on a phone, so neither button is a target you have to
                aim at; side by side once there is room for both. */}
            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                <button
                    type="button"
                    onClick={save}
                    disabled={form.processing}
                    className={cn(
                        'h-11 rounded-lg bg-fill px-4 text-[0.875rem] font-medium text-fill-ink sm:h-9',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-fill/90 disabled:opacity-45',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    Save changes
                </button>
                <button
                    type="button"
                    onClick={onDone}
                    className={cn(
                        'h-11 rounded-lg px-4 text-[0.875rem] text-ink-mute sm:h-9',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-surface-2 hover:text-ink',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}

/**
 * The photo, changed in place.
 *
 * The file input is hidden behind a real button rather than styled: a native
 * file input cannot be made to match anything, and every browser draws its own
 * idea of "No file chosen" beside it. The button owns the label, the input
 * owns the picker.
 */
function PhotoRow({ user }: { user: Participant }) {
    const picker = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /* Held between choosing and cropping. Nothing is uploaded until the square
       has been confirmed — the server crops whatever it is sent, so choosing
       the square is the only chance anyone gets to decide which one. */
    const [picked, setPicked] = useState<File | null>(null);

    const upload = async (file: File) => {
        setBusy(true);
        setError(null);

        /* Shrunk here so it fits through PHP's 2MB door, and so a phone is not
           pushing five megabytes up a mobile connection to end as a 256px
           square. The server re-decodes whatever arrives regardless. */
        const small = await downscale(file);

        router.post(
            '/settings/photo',
            { photo: small },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => setError(errors.photo ?? 'That photo could not be saved.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    const remove = () => {
        setBusy(true);
        setError(null);

        router.delete('/settings/photo', {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="border-b border-line py-2.5 last:border-b-0">
            <div className="flex min-h-[3.25rem] items-center justify-between gap-3">
                <span className="flex items-center gap-3">
                    {/* `online` omitted on purpose: a photo row is not
                        reporting presence, and the dot only renders when it
                        is told to. */}
                    <PresenceAvatar name={user.name} src={user.avatar_url} />
                    <span className="text-[0.875rem] text-ink">Photo</span>
                </span>

                <span className="flex shrink-0 items-center gap-1">
                    <input
                        ref={picker}
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        className="sr-only"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) setPicked(file);
                            // Cleared here rather than after the upload, so
                            // cancelling the crop still lets the same file be
                            // chosen again.
                            e.target.value = '';
                        }}
                    />
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => picker.current?.click()}
                        className={cn(
                            'h-10 rounded-lg px-3 text-[0.875rem] text-ink-mute',
                            'transition-colors duration-(--dur-micro) ease-out',
                            'hover:bg-surface-2 hover:text-ink active:bg-surface-2 disabled:opacity-45',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                        )}
                    >
                        {busy ? 'Saving…' : user.avatar_url ? 'Change' : 'Add photo'}
                    </button>

                    {user.avatar_url && !busy ? (
                        <button
                            type="button"
                            onClick={remove}
                            className={cn(
                                'h-10 rounded-lg px-3 text-[0.875rem] text-ink-mute -me-2',
                                'transition-colors duration-(--dur-micro) ease-out',
                                'hover:bg-bad/10 hover:text-bad active:bg-bad/15',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                            )}
                        >
                            Remove
                        </button>
                    ) : null}
                </span>
            </div>

            {error ? (
                <p role="alert" className="pb-1 text-[0.8125rem] leading-snug text-bad">
                    {error}
                </p>
            ) : null}

            <PhotoCropper
                file={picked}
                onCancel={() => setPicked(null)}
                onCropped={(cropped) => {
                    setPicked(null);
                    void upload(cropped);
                }}
            />
        </div>
    );
}

/**
 * The address you sign in with, changed in two steps.
 *
 * Sign-in here is passwordless, so this field *is* the credential — one
 * mistyped character in a single-step form would lock a person out for good,
 * with no password to fall back on and nobody able to undo it. The code goes
 * to the new address and the row only moves once it comes back.
 *
 * Which step is showing comes from the server, not from here: the pending
 * address lives in the session, so reloading the page in the middle lands
 * back on the code rather than losing it.
 */
function EmailRow({ account }: { account: Account }) {
    const [editing, setEditing] = useState(false);
    const address = useForm({ email: '' });
    /* Typed with an `email` key it never sends: confirming can still fail on
       the address rather than the code, when someone else claims it in the
       seconds between sending and confirming. The error has to have somewhere
       to land. */
    const code = useForm<{ code: string; email?: string }>({ code: '' });

    const pending = account.pending_email;

    const stop = () => {
        address.reset();
        address.clearErrors();
        code.reset();
        code.clearErrors();
        setEditing(false);
    };

    if (pending) {
        return (
            <div className="border-b border-line py-3 last:border-b-0">
                <p className="text-[0.875rem] text-ink">Confirm your new address</p>
                <p className="mt-0.5 text-[0.8125rem] leading-snug text-ink-mute">
                    We sent a code to <span className="text-ink-soft">{pending}</span>. Enter it to
                    finish the change.
                </p>

                <Input
                    id="setting-email-code"
                    autoFocus
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    value={code.data.code}
                    onChange={(e) => code.setData('code', e.target.value.replace(/\D/g, ''))}
                    aria-label="Confirmation code"
                    aria-invalid={code.errors.code ? true : undefined}
                    className="mt-3 h-11"
                />

                {code.errors.code || code.errors.email ? (
                    <p role="alert" className="mt-1.5 text-[0.8125rem] text-bad">
                        {code.errors.code ?? code.errors.email}
                    </p>
                ) : null}

                <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                    <button
                        type="button"
                        disabled={code.processing}
                        onClick={() =>
                            code.post('/settings/email/confirm', {
                                preserveScroll: true,
                                onSuccess: stop,
                            })
                        }
                        className={cn(
                            'h-11 rounded-lg bg-fill px-4 text-[0.875rem] font-medium text-fill-ink sm:h-9',
                            'transition-colors duration-(--dur-micro) ease-out',
                            'hover:bg-fill/90 disabled:opacity-45',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                        )}
                    >
                        Change email
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            router.delete('/settings/email', {
                                preserveScroll: true,
                                onFinish: stop,
                            })
                        }
                        className={cn(
                            'h-11 rounded-lg px-4 text-[0.875rem] text-ink-mute sm:h-9',
                            'transition-colors duration-(--dur-micro) ease-out',
                            'hover:bg-surface-2 hover:text-ink',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                        )}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        );
    }

    if (!editing) {
        return (
            <Row label="Email">
                <button
                    type="button"
                    onClick={() => setEditing(true)}
                    className={cn(
                        'flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 -me-2',
                        'text-[0.875rem] text-ink-mute',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-surface-2 hover:text-ink active:bg-surface-2',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    <span className="truncate">{account.email}</span>
                    <span className="sr-only">Change email</span>
                    <span aria-hidden className="shrink-0 text-ink-mute">
                        ✎
                    </span>
                </button>
            </Row>
        );
    }

    return (
        <div className="border-b border-line py-3 last:border-b-0">
            <label htmlFor="setting-email" className="text-[0.875rem] text-ink">
                New email
            </label>
            <p className="mt-0.5 text-[0.8125rem] leading-snug text-ink-mute">
                We will send a code there to make sure it reaches you. Nothing changes until it
                does.
            </p>

            <Input
                id="setting-email"
                autoFocus
                type="email"
                autoComplete="email"
                value={address.data.email}
                onChange={(e) => address.setData('email', e.target.value)}
                aria-invalid={address.errors.email ? true : undefined}
                className="mt-3 h-11"
            />

            {address.errors.email ? (
                <p role="alert" className="mt-1.5 text-[0.8125rem] text-bad">
                    {address.errors.email}
                </p>
            ) : null}

            <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                <button
                    type="button"
                    disabled={address.processing}
                    onClick={() => address.post('/settings/email', { preserveScroll: true })}
                    className={cn(
                        'h-11 rounded-lg bg-fill px-4 text-[0.875rem] font-medium text-fill-ink sm:h-9',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-fill/90 disabled:opacity-45',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    Send code
                </button>
                <button
                    type="button"
                    onClick={stop}
                    className={cn(
                        'h-11 rounded-lg px-4 text-[0.875rem] text-ink-mute sm:h-9',
                        'transition-colors duration-(--dur-micro) ease-out',
                        'hover:bg-surface-2 hover:text-ink',
                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                    )}
                >
                    Cancel
                </button>
            </div>
        </div>
    );
}

const THEMES: { value: Theme; label: string; Icon: typeof Sun }[] = [
    { value: 'system', label: 'System', Icon: Monitor },
    { value: 'light', label: 'Light', Icon: Sun },
    { value: 'dark', label: 'Dark', Icon: Moon },
];

/**
 * Three states, not a switch. A two-way toggle cannot say "follow the
 * machine", which is the one most people want and the one that makes an app
 * go dark at sunset without being asked.
 */
function ThemeControl() {
    const { theme, setTheme } = useTheme();

    return (
        <div
            role="radiogroup"
            aria-label="Theme"
            className="flex w-full gap-0.5 rounded-lg bg-surface-2 p-0.5 md:w-auto"
        >
            {THEMES.map(({ value, label, Icon }) => {
                const on = theme === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => setTheme(value)}
                        className={cn(
                            'flex h-9 flex-1 items-center justify-center gap-1.5 rounded-md px-3 md:flex-none',
                            'text-[0.8125rem] transition-colors duration-(--dur-micro) ease-out',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                            on ? 'bg-surface text-ink shadow-soft-sm' : 'text-ink-mute hover:text-ink',
                        )}
                    >
                        <Icon className="size-4" aria-hidden />
                        {label}
                    </button>
                );
            })}
        </div>
    );
}

interface Props {
    currentUser: Participant;
    account: Account;
    push: { state: PushState; enable: () => void; disable: () => void };
}

export function SettingsPane({ currentUser, account, push }: Props) {
    const [editing, setEditing] = useState<'name' | 'username' | null>(null);

    const form = useForm({ name: currentUser.name, username: currentUser.username ?? '' });

    const stopEditing = () => {
        form.clearErrors();
        form.setData({ name: currentUser.name, username: currentUser.username ?? '' });
        setEditing(null);
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/* The only thing telling a phone where it is — the rail that says
                so on a desktop is not on screen at this width. */}
            <header className="flex h-14 shrink-0 items-center border-b border-line px-4 md:px-6">
                <h1 className="text-[1.0625rem] font-semibold text-ink">Settings</h1>
            </header>

            {/* pb-24 clears the floating nav, which sits over this pane on a
                phone exactly as it does over the conversation list. */}
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-24 md:px-6 md:pb-10">
                <div className="mx-auto w-full max-w-[34rem]">
                    <Group title="You" note="Everyone you chat with sees these.">
                        <PhotoRow user={currentUser} />

                        <IdentityRow
                            label="Name"
                            field="name"
                            value={currentUser.name}
                            form={form}
                            editing={editing === 'name'}
                            onEdit={() => setEditing('name')}
                            onDone={stopEditing}
                        />

                        <IdentityRow
                            label="Username"
                            field="username"
                            value={currentUser.username ?? ''}
                            prefix="@"
                            form={form}
                            editing={editing === 'username'}
                            onEdit={() => setEditing('username')}
                            onDone={stopEditing}
                        />
                    </Group>

                    <Group title="Account" note="Follows you to every device you sign in on.">
                        <EmailRow account={account} />

                        <Row label="Sign out">
                            <button
                                type="button"
                                onClick={() => router.post('/logout')}
                                className={cn(
                                    'flex h-9 items-center gap-1.5 rounded-lg px-3 -me-2',
                                    'text-[0.875rem] text-bad',
                                    'transition-colors duration-(--dur-micro) ease-out',
                                    'hover:bg-bad/10 active:bg-bad/15',
                                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                                )}
                            >
                                <LogOut className="size-4" aria-hidden />
                                Sign out
                            </button>
                        </Row>
                    </Group>

                    <Group title="This device" note="Stored in this browser. Your other devices keep their own.">
                        <Row label="Theme" stacked>
                            <ThemeControl />
                        </Row>

                        {push.state === 'unsupported' ? null : (
                            <Row label="Notifications">
                                <Switch
                                    checked={push.state === 'on'}
                                    disabled={push.state === 'denied' || push.state === 'busy'}
                                    onCheckedChange={(on) => (on ? push.enable() : push.disable())}
                                    aria-label="Notify me about new messages"
                                />
                            </Row>
                        )}

                        {push.state === 'denied' ? (
                            <p className="pt-2 text-[0.8125rem] leading-snug text-ink-mute">
                                Notifications are blocked for this site. Turn them back on in your
                                browser settings.
                            </p>
                        ) : null}

                        {/* Shown only on an iPhone that has not been installed,
                            and this is where it belongs: beside the switch it
                            explains the absence of. */}
                        <InstallHint state={push.state} className="mt-3" />
                    </Group>
                </div>
            </div>
        </div>
    );
}
