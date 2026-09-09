import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Paperclip, SendHorizontal } from 'lucide-react';
import { useRef, useState, type ChangeEvent, type KeyboardEvent } from 'react';

const MAX_ROWS_PX = 160;

interface Props {
    /** Conversation title, so the field says what it is actually sending into. */
    title: string;
    disabled?: boolean;
    onSend: (body: string) => void;
    onTyping: () => void;
}

export function Composer({ title, disabled = false, onSend, onTyping }: Props) {
    const [body, setBody] = useState('');
    const areaRef = useRef<HTMLTextAreaElement>(null);
    const canSend = body.trim().length > 0 && !disabled;

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
        onSend(body.trim());
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
        }
    };

    return (
        <div className="border-t border-line bg-page px-4 py-3 md:px-6">
            <div className="mx-auto flex w-full max-w-[110rem] items-end gap-2">
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
                        aria-label={`Message ${title}`}
                        placeholder="Write a message"
                        className={cn(
                            'max-h-40 min-h-11 w-full resize-none bg-transparent px-3.5 py-3',
                            'text-[0.875rem] leading-[1.45] text-ink placeholder:text-ink-mute',
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
                    <SendHorizontal className="size-5" aria-hidden />
                    <span className="sr-only">Send message</span>
                </Button>
            </div>
        </div>
    );
}
