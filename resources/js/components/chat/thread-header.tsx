import { PresenceAvatar } from '@/components/chat/presence-avatar';
import { Button } from '@/components/ui/button';
import { conversationTitle, counterpart } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { Conversation, Participant } from '@/types';
import { ArrowLeft, Info, Phone, Search, Users } from 'lucide-react';

interface Props {
    conversation: Conversation;
    currentUser: Participant;
    /** Returns to the list. Only reachable below md, where the panes swap. */
    onBack: () => void;
}

export function ThreadHeader({ conversation, currentUser, onBack }: Props) {
    const title = conversationTitle(conversation, currentUser.id);
    const other = counterpart(conversation, currentUser.id);
    const isGroup = conversation.type === 'group';

    /* The subtitle answers the one question a header is for: who is actually
       here right now. For a group that is a count, for a direct chat it is a
       single word — and in both cases it is text, not just a coloured dot. */
    const online = conversation.participants.filter((p) => p.online).length;
    const subtitle = isGroup
        ? `${conversation.participants.length} members · ${online} online`
        : other?.online
          ? 'Online'
          : 'Offline';

    return (
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-page px-4 md:px-6">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onBack}
                className="-ms-2 size-11 shrink-0 rounded-full text-ink-mute hover:bg-surface-2 hover:text-ink active:bg-surface-2 md:hidden"
            >
                <ArrowLeft className="size-5" aria-hidden />
                <span className="sr-only">Back to chats</span>
            </Button>

            {isGroup ? (
                <span className="grid size-9 shrink-0 place-items-center rounded-full border border-line bg-surface-2 text-ink-soft">
                    <Users className="size-4" aria-hidden />
                </span>
            ) : (
                <PresenceAvatar name={title} src={other?.avatar_url} online={other?.online} size="sm" />
            )}

            <div className="flex min-w-0 flex-col">
                <h2 className="truncate text-[0.9375rem] leading-tight font-semibold tracking-[-0.01em]">
                    {title}
                </h2>
                <p className="truncate text-[0.75rem] text-ink-mute">{subtitle}</p>
            </div>

            <div className="ms-auto flex items-center gap-1">
                {[
                    { Icon: Search, label: 'Search in conversation' },
                    { Icon: Phone, label: 'Start a call' },
                    { Icon: Info, label: 'Conversation details' },
                ].map(({ Icon, label }) => (
                    <Button
                        key={label}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className={cn(
                            'size-11 rounded-full text-ink-mute hover:bg-surface-2 hover:text-ink active:bg-surface-2',
                            /* Three icons crowd the title off a 320px header;
                               only the details button survives the squeeze. */
                            label === 'Conversation details' ? '' : 'hidden sm:inline-flex',
                        )}
                    >
                        <Icon className="size-5" aria-hidden />
                        <span className="sr-only">{label}</span>
                    </Button>
                ))}
            </div>
        </header>
    );
}
