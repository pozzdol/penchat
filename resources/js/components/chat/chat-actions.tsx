import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { counterpart } from '@/lib/chat';
import type { Conversation, Participant } from '@/types';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Clearing empties a conversation; deleting also takes it off the list. Both
 * are per-viewer and neither erases anything, which is why only one of them —
 * and only when the checkbox is ticked — reaches the other person at all.
 *
 * The dialog lives here rather than in the two places that open it, so the
 * wording of a destructive action has exactly one definition.
 */
export type ChatAction = 'clear' | 'delete' | null;

export function ChatActionDialog({
    conversation,
    currentUser,
    action,
    onClose,
}: {
    conversation: Conversation;
    currentUser: Participant;
    action: ChatAction;
    onClose: () => void;
}) {
    const [alsoTheirs, setAlsoTheirs] = useState(false);
    const [working, setWorking] = useState(false);
    const other = counterpart(conversation, currentUser.id);
    const them = other?.name ?? 'them';

    const close = () => {
        onClose();
        setAlsoTheirs(false);
    };

    const run = () => {
        setWorking(true);

        const done = { onFinish: () => { setWorking(false); close(); }, preserveScroll: true };

        if (action === 'clear') {
            router.delete(`/conversations/${conversation.id}/history`, done);
        } else {
            router.delete(`/conversations/${conversation.id}`, {
                ...done,
                data: { also_for_other: alsoTheirs },
            });
        }
    };

    const clearing = action === 'clear';

    return (
        <Dialog open={action !== null} onOpenChange={(open) => (open ? null : close())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{clearing ? 'Clear history' : 'Delete chat'}</DialogTitle>
                    <DialogDescription>
                        {clearing
                            ? `Empty this conversation for you. ${
                                  conversation.type === 'group' ? 'Everyone else' : them
                              } keeps their copy, and new messages still arrive here.`
                            : `This chat leaves your list. It comes back on its own if ${them} writes again.`}
                    </DialogDescription>
                </DialogHeader>

                {!clearing && other ? (
                    <label className="flex cursor-pointer items-start gap-3 rounded-lg bg-surface-2 p-3">
                        <Checkbox
                            checked={alsoTheirs}
                            onCheckedChange={(v) => setAlsoTheirs(v === true)}
                            className="mt-0.5"
                        />
                        <span className="flex flex-col gap-1">
                            <span className="text-[0.875rem] font-medium">
                                Also delete for {other.name}
                            </span>
                            <span className="text-[0.8125rem] text-ink-mute">
                                They lose their copy of this conversation too. You cannot undo this
                                for them.
                            </span>
                        </span>
                    </label>
                ) : null}

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={close} className="h-11 px-4">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={run}
                        disabled={working}
                        className="h-11 px-4"
                    >
                        {clearing ? 'Clear history' : 'Delete chat'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
