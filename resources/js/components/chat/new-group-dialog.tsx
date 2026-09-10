import { Field, UsernameInput } from '@/components/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * Create a group. Members are added by the same exact-match handle lookup that
 * opens a direct chat, so there is one way to name a person in this app.
 *
 * A group of one is allowed — people build the room before they fill it — so
 * only the name is required.
 */
export function NewGroupDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [draft, setDraft] = useState('');
    const form = useForm<{ name: string; usernames: string[] }>({ name: '', usernames: [] });

    const change = (next: boolean) => {
        onOpenChange(next);
        if (!next) {
            setDraft('');
            form.reset();
            form.clearErrors();
        }
    };

    const add = () => {
        const handle = draft.trim();
        if (!handle || form.data.usernames.includes(handle)) {
            setDraft('');
            return;
        }

        form.setData('usernames', [...form.data.usernames, handle]);
        setDraft('');
    };

    const remove = (handle: string) =>
        form.setData(
            'usernames',
            form.data.usernames.filter((u) => u !== handle),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Whatever is still in the field counts: forgetting to press + should
        // not silently drop the person you just typed.
        const usernames = draft.trim() && !form.data.usernames.includes(draft.trim())
            ? [...form.data.usernames, draft.trim()]
            : form.data.usernames;

        form.transform((data) => ({ ...data, usernames }));
        form.post('/conversations', {
            preserveState: true,
            onSuccess: () => change(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New group</DialogTitle>
                    <DialogDescription>
                        Name the group and add people by username. You can add more later.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field label="Group name" htmlFor="group-name" error={form.errors.name}>
                        <Input
                            id="group-name"
                            autoFocus
                            required
                            maxLength={60}
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            aria-invalid={form.errors.name ? true : undefined}
                            className="h-11"
                        />
                    </Field>

                    <Field
                        label="Add people"
                        htmlFor="group-username"
                        error={form.errors.usernames}
                        hint="Press Enter after each username."
                    >
                        <div className="flex gap-2">
                            <UsernameInput
                                id="group-username"
                                value={draft}
                                onValueChange={setDraft}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        // Enter adds a name; it must not submit
                                        // the half-filled form.
                                        e.preventDefault();
                                        add();
                                    }
                                }}
                                aria-invalid={form.errors.usernames ? true : undefined}
                                className="flex-1"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={add}
                                disabled={draft.trim().length === 0}
                                className="size-11 shrink-0 p-0"
                            >
                                <Plus className="size-4" aria-hidden />
                                <span className="sr-only">Add {draft || 'username'}</span>
                            </Button>
                        </div>
                    </Field>

                    {form.data.usernames.length > 0 ? (
                        <ul className="-mt-2 flex flex-wrap gap-1.5">
                            {form.data.usernames.map((handle) => (
                                <li key={handle}>
                                    <button
                                        type="button"
                                        onClick={() => remove(handle)}
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full bg-surface-2 py-1 ps-2.5 pe-1.5',
                                            'text-[0.8125rem] text-ink transition-colors duration-(--dur-micro) ease-out',
                                            'hover:bg-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info',
                                        )}
                                    >
                                        @{handle}
                                        <X className="size-3.5 text-ink-mute" aria-hidden />
                                        <span className="sr-only">Remove</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    <Button
                        type="submit"
                        disabled={form.processing || form.data.name.trim().length === 0}
                        className="h-11 w-full"
                    >
                        {form.processing ? 'Creating…' : 'Create group'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
