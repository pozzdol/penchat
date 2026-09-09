import { Composer } from '@/components/chat/composer';
import { ConversationList } from '@/components/chat/conversation-list';
import { Rail } from '@/components/chat/rail';
import { Thread, ThreadEmpty } from '@/components/chat/thread';
import { ThreadHeader } from '@/components/chat/thread-header';
import { TooltipProvider } from '@/components/ui/tooltip';
import { buildThread, conversationTitle } from '@/lib/chat';
import { cn } from '@/lib/utils';
import type { ChatPageProps, Message } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * The Workbench shell: rail · list · thread. Three fixed panes, one scroll
 * region each, and the document itself never scrolls.
 *
 * State here is local and the data is a server-rendered fixture — the realtime
 * layer (HTTP POST -> broadcast, Echo subscription, presence, whisper) is not
 * wired yet. See the note in ChatController.
 */
export default function Chat({
    current_user,
    conversations,
    active_conversation_id,
    messages,
}: ChatPageProps) {
    const [activeId, setActiveId] = useState<number | null>(active_conversation_id);
    const [thread, setThread] = useState<Message[]>(messages);

    const active = useMemo(
        () => conversations.find((c) => c.id === activeId) ?? null,
        [conversations, activeId],
    );

    const items = useMemo(
        () => (active ? buildThread(thread, active.participants, new Date()) : []),
        [thread, active],
    );

    /* Optimistic append: the message lands in the thread immediately and
       carries `pending` until the server answers. The retry path re-arms the
       same row rather than creating a second one. */
    const send = (body: string) => {
        if (!active) return;

        setThread((prev) => [
            ...prev,
            {
                id: Date.now(),
                conversation_id: active.id,
                user_id: current_user.id,
                body,
                created_at: new Date().toISOString(),
                attachments: [],
                delivery: 'pending',
            },
        ]);
    };

    const retry = (messageId: number) => {
        setThread((prev) =>
            prev.map((m) => (m.id === messageId ? { ...m, delivery: 'pending' } : m)),
        );
    };

    const select = (id: number) => {
        setActiveId(id);
        setThread(id === active_conversation_id ? messages : []);
    };

    return (
        <TooltipProvider delayDuration={600}>
            <Head title={active ? conversationTitle(active, current_user.id) : 'Chats'} />

            <div className="flex h-dvh w-full overflow-hidden bg-page text-ink">
                <Rail active="chats" />

                {/* Below md exactly one of these two is mounted-visible: the
                    list, or the thread that replaced it. */}
                <ConversationList
                    conversations={conversations}
                    currentUser={current_user}
                    activeId={activeId}
                    onSelect={select}
                    className={cn(active && 'max-md:hidden')}
                />

                <main
                    className={cn(
                        'flex min-w-0 flex-1 flex-col bg-page',
                        !active && 'max-md:hidden',
                    )}
                >
                    {active ? (
                        <>
                            <ThreadHeader
                                conversation={active}
                                currentUser={current_user}
                                onBack={() => setActiveId(null)}
                            />
                            <Thread
                                conversation={active}
                                items={items}
                                currentUser={current_user}
                                typing={[]}
                                onRetry={retry}
                            />
                            <Composer
                                title={conversationTitle(active, current_user.id)}
                                onSend={send}
                                onTyping={() => {
                                    /* whisper goes here once Reverb is wired */
                                }}
                            />
                        </>
                    ) : (
                        <ThreadEmpty />
                    )}
                </main>
            </div>
        </TooltipProvider>
    );
}
