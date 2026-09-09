import { BrandLockup } from '@/components/chat/brand';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { LoginPageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

/**
 * One page, three steps. The server decides the step from the session, so the
 * browser can never land on a step it has not earned.
 */
export default function Login({ step, email }: LoginPageProps) {
    return (
        <>
            <Head title="Sign in" />

            <main className="grid min-h-dvh place-items-center bg-page px-4 py-10 text-ink">
                <div className="w-full max-w-sm">
                    <BrandLockup tagline className="mb-8 w-full" />

                    <section className="rounded-2xl border border-line bg-surface p-6">
                        {step === 'email' ? <EmailStep /> : null}
                        {step === 'code' && email ? <CodeStep email={email} /> : null}
                        {step === 'name' && email ? <NameStep email={email} /> : null}
                    </section>
                </div>
            </main>
        </>
    );
}

function EmailStep() {
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login/email');
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <Intro title="Sign in to PenChat">
                Enter your email and we will send a six-digit code. There is no password.
            </Intro>

            <Field label="Email" htmlFor="email" error={form.errors.email}>
                <Input
                    id="email"
                    type="email"
                    autoComplete="email"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    aria-invalid={form.errors.email ? true : undefined}
                    className="h-11"
                />
            </Field>

            <Button type="submit" disabled={form.processing} className="h-11 w-full">
                {form.processing ? 'Sending…' : 'Send code'}
            </Button>
        </form>
    );
}

function CodeStep({ email }: { email: string }) {
    const form = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login/code');
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <Intro title="Check your email">
                We sent a code to <span className="font-medium text-ink">{email}</span>. It expires in
                ten minutes.
            </Intro>

            <Field label="Six-digit code" htmlFor="code" error={form.errors.code}>
                <Input
                    id="code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    autoFocus
                    required
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                    aria-invalid={form.errors.code ? true : undefined}
                    className="h-11 text-center text-lg tracking-[0.35em]"
                />
            </Field>

            <Button type="submit" disabled={form.processing} className="h-11 w-full">
                {form.processing ? 'Checking…' : 'Continue'}
            </Button>

            <div className="flex items-center justify-between text-[0.8125rem] text-ink-mute">
                <TextButton onClick={() => router.post('/login/email', { email })}>
                    Send a new code
                </TextButton>
                <TextButton onClick={() => router.post('/login/restart')}>Use a different email</TextButton>
            </div>
        </form>
    );
}

function NameStep({ email }: { email: string }) {
    const form = useForm({ name: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login/name');
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <Intro title="Welcome">
                <span className="font-medium text-ink">{email}</span> is verified. What should people
                see you as?
            </Intro>

            <Field label="Your name" htmlFor="name" error={form.errors.name}>
                <Input
                    id="name"
                    autoComplete="name"
                    autoFocus
                    required
                    maxLength={60}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    aria-invalid={form.errors.name ? true : undefined}
                    className="h-11"
                />
            </Field>

            <Button type="submit" disabled={form.processing} className="h-11 w-full">
                {form.processing ? 'Creating…' : 'Create account'}
            </Button>

            <div className="text-[0.8125rem] text-ink-mute">
                <TextButton onClick={() => router.post('/login/restart')}>Use a different email</TextButton>
            </div>
        </form>
    );
}

function Intro({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <h1 className="text-[1.125rem] leading-tight font-semibold tracking-[-0.01em]">{title}</h1>
            <p className="text-[0.875rem] leading-[1.5] text-ink-mute">{children}</p>
        </div>
    );
}

/** Label above, helper slot below with a reserved line so an error never shifts the form. */
function Field({
    label,
    htmlFor,
    error,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
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
                {error ?? ''}
            </p>
        </div>
    );
}

function TextButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-sm underline-offset-2 hover:text-ink hover:underline active:opacity-70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
        >
            {children}
        </button>
    );
}
