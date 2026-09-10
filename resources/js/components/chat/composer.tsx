import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Check, CornerUpLeft, Paperclip, Pencil, SendHorizontal, X } from 'lucide-react';
import { useEffect, useRef, useState, type ChangeEvent, type KeyboardEvent } from 'react';

const MAX_ROWS_PX = 160;

/**
 * The strip above the field saying what this message will be. Editing and
 * replying are the same shape on purpose — both answer "why does the composer
 * look different right now", and two designs for one question is one too many.
 */
function Banner({
    Icon,
    label,
    body,
    dismiss,
    onDismiss,
}: {
    Icon: typeof Pencil;
    label: string;
    body: string;
    dismiss: string;
    onDismiss: () => void;
}) {
    return (
        <div className="mx-auto mb-2 flex w-full max-w-[110rem] items-center gap-2 rounded-lg bg-surface-2 py-1.5 ps-3 pe-1.5">
            <Icon className="size-4 shrink-0 text-ink-mute" aria-hidden />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="text-[0.6875rem] font-medium text-ink-soft">{label}</span>
                <span className="truncate text-[0.8125rem] text-ink-mute">{body}</span>
            </span>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onDismiss}
                className="size-8 shrink-0 rounded-full text-ink-mute hover:bg-line hover:text-ink"
            >
                <X className="size-4" aria-hidden />
                <span className="sr-only">{dismiss}</span>
            </Button>
        </div>
    );
}

interface Props {
    /** Conversation title, so the field says what it is actually sending into. */
    title: string;
    disabled?: boolean;
    /**
     * The message being rewritten, if any. Editing happens in the composer
     * rather than in the bubble: one place accepts text in this app, and it is
     * the one already under the reader's hands.
     */
    editing: { id: string; body: string } | null;
    /**
     * Why the last send was refused — a rate limit, or a paused account.
     * Without it a throttled message is just a bubble that failed for no
     * stated reason, which reads as the app being broken rather than as the
     * app saying no.
     */
    notice: string | null;
    /**
     * The message being replied to, if any. Mutually exclusive with `editing`
     * — you are either rewriting your own words or answering someone else's,
     * and the composer can only be one thing at a time.
     */
    replying: { id: string; author: string; body: string } | null;
    onSend: (body: string) => void;
    onEdit: (body: string) => void;
    onCancelEdit: () => void;
    onCancelReply: () => void;
    onTyping: () => void;
}

export function Composer({
    title,
    disabled = false,
    editing,
    notice,
    replying,
    onSend,
    onEdit,
    onCancelEdit,
    onCancelReply,
    onTyping,
}: Props) {
    const [body, setBody] = useState('');
    const areaRef = useRef<HTMLTextAreaElement>(null);
    const canSend = body.trim().length > 0 && !disabled;

    /* Keyed on the id, not the object: re-rendering while someone is halfway
       through rewording must not throw their work away and start over. */
    useEffect(() => {
        if (!editing) return;

        setBody(editing.body);
        const el = areaRef.current;
        if (el) {
            el.focus();
            el.setSelectionRange(editing.body.length, editing.body.length);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing?.id]);

    const cancel = () => {
        onCancelEdit();
        setBody('');
        if (areaRef.current) areaRef.current.style.height = 'auto';
    };

    /* Auto-grow to a ceiling, then scroll. Height is set imperatively rather
       than animated — transitioning height on every keystroke is a layout
       thrash the reader can feel. */
    const resize = (el: HTMLTextAreaElement) => {
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, MAX_ROWS_PX)}px`;
    };

    const change = (e: ChangeEvent<HTMLTextAreaElement>) => {
        setBody(e.target.value);
        resize(e.target);
        onTyping();
    };

    const send = () => {
        if (!canSend) return;

        if (editing) {
            onEdit(body.trim());
        } else {
            onSend(body.trim());
        }

        setBody('');
        if (areaRef.current) {
            areaRef.current.style.height = 'auto';
            areaRef.current.focus();
        }
    };

    /* Enter sends, Shift+Enter breaks the line. The reverse is the single most
       complained-about default in every chat client that has tried it. */
    const keydown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
            return;
        }

        if (e.key === 'Escape' && (editing || replying)) {
            e.preventDefault();
            editing ? cancel() : onCancelReply();
        }
    };

    return (
        <div className="border-t border-line bg-page px-4 py-3 md:px-6">
            {editing || replying ? (
                <Banner
                    Icon={editing ? Pencil : CornerUpLeft}
                    label={editing ? 'Editing message' : `Replying to ${replying?.author}`}
                    body={editing ? editing.body : (replying?.body ?? '')}
                    dismiss={editing ? 'Cancel edit' : 'Cancel reply'}
                    onDismiss={editing ? cancel : onCancelReply}
                />
            ) : null}

            {notice ? (
                <p
                    role="alert"
                    className="mx-auto mb-2 w-full max-w-[110rem] text-[0.8125rem] text-bad"
                >
                    {notice}
                </p>
            ) : null}

            <div className="mx-auto flex w-full max-w-[110rem] items-end gap-2">
                {editing ? null : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={disabled}
                        className="size-11 shrink-0 rounded-full text-ink-mute hover:bg-surface-2 hover:text-ink active:bg-surface-2"
                    >
                        <Paperclip className="size-5" aria-hidden />
                        <span className="sr-only">Attach a file</span>
                    </Button>
                )}

                <div
                    className={cn(
                        'flex min-w-0 flex-1 items-end rounded-2xl border border-line bg-surface',
                        /* The ring lives on the wrapper, not the textarea, so
                           focus surrounds the whole control. Border width never
                           changes between states — that is the layout shift. */
                        'focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-info',
                    )}
                >
                    <textarea
                        ref={areaRef}
                        rows={1}
                        value={body}
                        onChange={change}
                        onKeyDown={keydown}
                        disabled={disabled}
                        aria-label={editing ? 'Edit message' : `Message ${title}`}
                        placeholder={editing ? 'Edit your message' : 'Write a message'}
                        className={cn(
                            'max-h-40 min-h-11 w-full resize-none bg-transparent px-3.5',
                            /* 16px on a phone, and it has to be exactly that:
                               Safari on iOS zooms the page in whenever a
                               focused field computes under 16px, and it never
                               zooms back out. The padding shrinks by the same
                               amount the taller line box grows, so the field
                               still matches the 44px buttons beside it. */
                            'py-2.5 text-base md:py-3 md:text-[0.875rem]',
                            'leading-[1.45] text-ink placeholder:text-ink-mute',
                            'focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-55',
                        )}
                    />
                </div>

                <Button
                    type="button"
                    onClick={send}
                    disabled={!canSend}
                    className={cn(
                        'size-11 shrink-0 rounded-full p-0',
                        /* One press primitive, 60ms — the only motion on send.
                           No success toast: the message appearing in the thread
                           is the confirmation. */
                        'transition-transform duration-(--dur-micro) ease-out',
                        'active:translate-y-px disabled:opacity-40',
                    )}
                >
                    {editing ? (
                        <Check className="size-5" aria-hidden />
                    ) : (
                        <SendHorizontal className="size-5" aria-hidden />
                    )}
                    <span className="sr-only">{editing ? 'Save changes' : 'Send message'}</span>
                </Button>
            </div>
        </div>
    );
}
