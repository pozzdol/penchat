import { Field, UsernameInput } from '@/components/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

/**
 * Start a direct chat by handle. There is no friend request: knowing someone's
 * username is the introduction, so a match opens the conversation immediately.
 *
 * Controlled rather than self-triggering, because it is opened from a menu item
 * that cannot also be the dialog's trigger.
 */
export function NewChatDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ username: '' });

    const change = (next: boolean) => {
        onOpenChange(next);
        if (!next) {
            form.reset();
            form.clearErrors();
        }
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/conversations/direct', {
            // A rejected username must leave the dialog open with the text still
            // in it; a successful one lands on /c/{id}, which is the same page
            // component, so preserved state would leave the dialog hanging open
            // over the conversation it just opened. Close it explicitly.
            preserveState: true,
            onSuccess: () => change(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New chat</DialogTitle>
                    <DialogDescription>
                        Enter someone’s username to open a conversation with them.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field label="Username" htmlFor="new-chat-username" error={form.errors.username}>
                        <UsernameInput
                            id="new-chat-username"
                            autoFocus
                            required
                            value={form.data.username}
                            onValueChange={(v) => form.setData('username', v)}
                            aria-invalid={form.errors.username ? true : undefined}
                        />
                    </Field>

                    <Button
                        type="submit"
                        disabled={form.processing || form.data.username.length === 0}
                        className="h-11 w-full"
                    >
                        {form.processing ? 'Opening…' : 'Start chat'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
