import { ChatActionDialog, type ChatAction } from '@/components/chat/chat-actions';
import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { Field, UsernameInput } from '@/components/field';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { conversationTitle, counterpart } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { Conversation, Participant } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { Check, EllipsisVertical, Eraser, LogOut, Pencil, Trash2, UserPlus, Users, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Props {
    conversation: Conversation;
    currentUser: Participant;
    onClose: () => void;
}

/**
 * The fourth pane: group info, or a contact card for a direct chat. Slides in
 * beside the thread rather than covering it, so a long member list has room
 * without scrolling inside a scroll.
 *
 * Every action renders from `conversation.can`, which the server computed from
 * the same policy it enforces — the rules are not re-derived here.
 */
export function DetailsPanel({ conversation, currentUser, onClose }: Props) {
    const isGroup = conversation.type === 'group';
    const title = conversationTitle(conversation, currentUser.id);
    const other = counterpart(conversation, currentUser.id);

    return (
        <aside
            aria-label={isGroup ? 'Group info' : 'Contact info'}
            className={cn(
                'flex w-full shrink-0 flex-col border-s border-line bg-page',
                'md:w-84',
                // Below md there is no room beside the thread, so it covers it.
                'max-md:absolute max-md:inset-0 max-md:z-10',
            )}
        >
            <header className="flex h-16 shrink-0 items-center gap-2 border-b border-line px-4">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onClose}
                    className="-ms-2 size-11 shrink-0 rounded-full text-ink-mute hover:bg-surface-2 hover:text-ink"
                >
                    <X className="size-5" aria-hidden />
                    <span className="sr-only">Close</span>
                </Button>
                <h2 className="text-[0.9375rem] font-semibold tracking-[-0.01em]">
                    {isGroup ? 'Group info' : 'Contact info'}
                </h2>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <div className="flex flex-col items-center gap-3 px-6 py-7 text-center">
                    {isGroup ? (
                        <span className="grid size-20 place-items-center rounded-full border border-line bg-surface-2 text-ink-soft">
                            <Users className="size-8" aria-hidden />
                        </span>
                    ) : (
                        <PresenceAvatar
                            name={title}
                            src={other?.avatar_url}
                            online={other?.online}
                            size="lg"
                            className="[&>span:first-child]:size-20"
                        />
                    )}

                    {isGroup ? (
                        <GroupName conversation={conversation} title={title} />
                    ) : (
                        <div className="flex flex-col gap-1">
                            <p className="text-[1.125rem] font-semibold tracking-[-0.01em]">{title}</p>
                            {other?.username ? (
                                <p className="text-[0.8125rem] text-ink-mute">@{other.username}</p>
                            ) : null}
                        </div>
                    )}

                    {isGroup ? (
                        <p className="text-[0.8125rem] text-ink-mute">
                            Group · {conversation.participants.length}{' '}
                            {conversation.participants.length === 1 ? 'participant' : 'participants'}
                        </p>
                    ) : null}
                </div>

                {isGroup ? (
                    <>
                        <Settings conversation={conversation} />
                        <Members conversation={conversation} currentUser={currentUser} />
                    </>
                ) : null}

                <ChatActions conversation={conversation} currentUser={currentUser} />

                {isGroup ? <LeaveGroup conversation={conversation} /> : null}
            </div>
        </aside>
    );
}

/** Admins can rename in place; everyone else just reads it. */
function GroupName({ conversation, title }: { conversation: Conversation; title: string }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: conversation.name ?? '' });

    if (!conversation.can.update_settings) {
        return <p className="text-[1.125rem] font-semibold tracking-[-0.01em]">{title}</p>;
    }

    if (!editing) {
        return (
            <button
                type="button"
                onClick={() => {
                    form.setData('name', conversation.name ?? '');
                    setEditing(true);
                }}
                className="group inline-flex items-center gap-2 rounded-lg px-2 py-1 transition-colors duration-(--dur-micro) ease-out hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
            >
                <span className="text-[1.125rem] font-semibold tracking-[-0.01em]">{title}</span>
                <Pencil className="size-3.5 text-ink-mute" aria-hidden />
                <span className="sr-only">Rename group</span>
            </button>
        );
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/conversations/${conversation.id}`, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <form onSubmit={submit} className="flex w-full items-start gap-2">
            <Input
                autoFocus
                required
                maxLength={60}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                aria-label="Group name"
                aria-invalid={form.errors.name ? true : undefined}
                className="h-11"
            />
            <Button type="submit" disabled={form.processing} className="size-11 shrink-0 p-0">
                <Check className="size-4" aria-hidden />
                <span className="sr-only">Save name</span>
            </Button>
            <Button
                type="button"
                variant="ghost"
                onClick={() => setEditing(false)}
                className="size-11 shrink-0 p-0"
            >
                <X className="size-4" aria-hidden />
                <span className="sr-only">Cancel</span>
            </Button>
        </form>
    );
}

function Settings({ conversation }: { conversation: Conversation }) {
    if (!conversation.can.update_settings) {
        return null;
    }

    const flip = (field: 'members_can_add' | 'admins_can_promote', value: boolean) =>
        router.patch(
            `/conversations/${conversation.id}`,
            { [field]: value },
            { preserveState: true, preserveScroll: true, only: ['conversations'] },
        );

    return (
        <section className="border-t border-line px-6 py-4">
            <h3 className="mb-3 text-[0.8125rem] font-medium text-ink-mute">Permissions</h3>

            <Toggle
                label="Members can add people"
                checked={conversation.members_can_add}
                onChange={(v) => flip('members_can_add', v)}
            />

            {conversation.can.update_owner_settings ? (
                <Toggle
                    label="Admins can appoint admins"
                    hint="Only you can change this."
                    checked={conversation.admins_can_promote}
                    onChange={(v) => flip('admins_can_promote', v)}
                />
            ) : null}
        </section>
    );
}

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    const id = `toggle-${label.replace(/\s+/g, '-').toLowerCase()}`;

    return (
        <div className="flex items-start justify-between gap-4 py-2">
            <label htmlFor={id} className="flex cursor-pointer flex-col">
                <span className="text-[0.875rem]">{label}</span>
                {hint ? <span className="text-[0.75rem] text-ink-mute">{hint}</span> : null}
            </label>
            <Switch id={id} checked={checked} onCheckedChange={onChange} className="mt-0.5" />
        </div>
    );
}

function Members({
    conversation,
    currentUser,
}: {
    conversation: Conversation;
    currentUser: Participant;
}) {
    return (
        <section className="border-t border-line py-2">
            <h3 className="px-6 py-2 text-[0.8125rem] font-medium text-ink-mute">
                {conversation.participants.length}{' '}
                {conversation.participants.length === 1 ? 'participant' : 'participants'}
            </h3>

            {conversation.can.add_member ? <AddMember conversation={conversation} /> : null}

            <ul>
                {conversation.participants.map((person) => (
                    <MemberRow
                        key={person.id}
                        person={person}
                        conversation={conversation}
                        currentUser={currentUser}
                    />
                ))}
            </ul>
        </section>
    );
}

function AddMember({ conversation }: { conversation: Conversation }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ usernames: string[] }>({ usernames: [] });
    const [draft, setDraft] = useState('');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform(() => ({ usernames: [draft.trim()] }));
        form.post(`/conversations/${conversation.id}/members`, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setDraft('');
                setOpen(false);
                form.clearErrors();
            },
        });
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex w-full items-center gap-3 px-6 py-2.5 text-start transition-colors duration-(--dur-micro) ease-out hover:bg-surface-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-info"
            >
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-surface-2 text-ink-soft">
                    <UserPlus className="size-[1.125rem]" aria-hidden />
                </span>
                <span className="text-[0.875rem] font-medium">Add participant</span>
            </button>
        );
    }

    return (
        <form onSubmit={submit} className="px-6 py-2">
            <Field label="Username" htmlFor="add-member" error={form.errors.usernames}>
                <UsernameInput
                    id="add-member"
                    autoFocus
                    required
                    value={draft}
                    onValueChange={setDraft}
                    aria-invalid={form.errors.usernames ? true : undefined}
                />
            </Field>
            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing || !draft.trim()} className="h-10 flex-1">
                    {form.processing ? 'Adding…' : 'Add'}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        setOpen(false);
                        setDraft('');
                        form.clearErrors();
                    }}
                    className="h-10"
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function MemberRow({
    person,
    conversation,
    currentUser,
}: {
    person: Participant;
    conversation: Conversation;
    currentUser: Participant;
}) {
    const isSelf = person.id === currentUser.id;
    const isOwner = conversation.owner_id === person.id;
    const isAdmin = person.role === 'admin';

    // The owner is the one guaranteed admin, so they are never a valid target.
    const canPromote = conversation.can.manage_admins && !isOwner;
    const canRemove = conversation.can.remove_member && !isOwner && !isSelf;
    const canTransfer = conversation.can.transfer_ownership && !isOwner && !isSelf;
    const hasActions = canPromote || canRemove || canTransfer;

    const act = (fn: () => void) => () => fn();

    const setRole = (role: 'admin' | 'member') =>
        router.patch(
            `/conversations/${conversation.id}/members/${person.id}`,
            { role },
            { preserveState: true, preserveScroll: true, only: ['conversations'] },
        );

    const remove = () =>
        router.delete(`/conversations/${conversation.id}/members/${person.id}`, {
            preserveState: true,
            preserveScroll: true,
            only: ['conversations'],
        });

    const transfer = () => {
        if (!window.confirm(`Make @${person.username} the owner of this group?`)) return;

        router.post(
            `/conversations/${conversation.id}/owner/${person.id}`,
            {},
            { preserveState: true, preserveScroll: true, only: ['conversations'] },
        );
    };

    return (
        <li className="flex items-center gap-3 px-6 py-2.5">
            <PresenceAvatar name={person.name} src={person.avatar_url} online={person.online} />

            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-[0.875rem] font-medium">
                    {person.name}
                    {isSelf ? <span className="text-ink-mute"> (you)</span> : null}
                </span>
                {person.username ? (
                    <span className="truncate text-[0.75rem] text-ink-mute">@{person.username}</span>
                ) : null}
            </span>

            {isOwner || isAdmin ? (
                <span className="shrink-0 rounded-full bg-surface-2 px-2 py-0.5 text-[0.6875rem] text-ink-soft">
                    {isOwner ? 'Owner' : 'Group admin'}
                </span>
            ) : null}

            {hasActions ? (
                <DropdownMenu>
                    <DropdownMenuTrigger className="grid size-9 shrink-0 place-items-center rounded-full text-ink-mute transition-colors duration-(--dur-micro) ease-out hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info">
                        <EllipsisVertical className="size-4" aria-hidden />
                        <span className="sr-only">Options for {person.name}</span>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end" className="w-52">
                        {canPromote ? (
                            <DropdownMenuItem
                                onSelect={act(() => setRole(isAdmin ? 'member' : 'admin'))}
                            >
                                {isAdmin ? 'Dismiss as admin' : 'Make group admin'}
                            </DropdownMenuItem>
                        ) : null}
                        {canTransfer ? (
                            <DropdownMenuItem onSelect={act(transfer)}>
                                Make group owner
                            </DropdownMenuItem>
                        ) : null}
                        {canRemove ? (
                            <DropdownMenuItem variant="destructive" onSelect={act(remove)}>
                                Remove from group
                            </DropdownMenuItem>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </li>
    );
}

/**
 * The same two actions the list row offers, for someone who is already reading
 * the conversation. Exit stays below them: leaving is about the group, these
 * are only about your copy of it.
 */
function ChatActions({
    conversation,
    currentUser,
}: {
    conversation: Conversation;
    currentUser: Participant;
}) {
    const [action, setAction] = useState<ChatAction>(null);

    return (
        <section className="border-t border-line p-2">
            <PanelAction icon={Eraser} label="Clear history" onClick={() => setAction('clear')} />

            {conversation.can.delete_chat ? (
                <PanelAction
                    icon={Trash2}
                    label="Delete chat"
                    destructive
                    onClick={() => setAction('delete')}
                />
            ) : null}

            <ChatActionDialog
                conversation={conversation}
                currentUser={currentUser}
                action={action}
                onClose={() => setAction(null)}
            />
        </section>
    );
}

function PanelAction({
    icon: Icon,
    label,
    destructive = false,
    onClick,
}: {
    icon: typeof Eraser;
    label: string;
    destructive?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-3 rounded-lg px-4 py-2.5 text-start',
                'transition-colors duration-(--dur-micro) ease-out',
                'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-info',
                destructive ? 'text-bad hover:bg-bad/10' : 'text-ink hover:bg-surface-2',
            )}
        >
            <Icon className="size-[1.125rem]" aria-hidden />
            <span className="text-[0.875rem] font-medium">{label}</span>
        </button>
    );
}

function LeaveGroup({ conversation }: { conversation: Conversation }) {
    if (!conversation.can.leave) {
        return null;
    }

    const leave = () => {
        if (!window.confirm('Leave this group? You will stop receiving its messages.')) return;

        router.delete(`/conversations/${conversation.id}/membership`);
    };

    return (
        <section className="border-t border-line p-2">
            <button
                type="button"
                onClick={leave}
                className="flex w-full items-center gap-3 rounded-lg px-4 py-2.5 text-start text-bad transition-colors duration-(--dur-micro) ease-out hover:bg-bad/10 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-info"
            >
                <LogOut className="size-[1.125rem]" aria-hidden />
                <span className="text-[0.875rem] font-medium">Exit group</span>
            </button>
        </section>
    );
}
