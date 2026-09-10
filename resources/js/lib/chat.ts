import type { Conversation, Message, Participant } from '@/types';

/**
 * A direct chat is a group with two participants (AGENTS.md § data model), so
 * only the *presentation* differs: a group carries its own name, a direct chat
 * borrows the other participant's.
 */
export function conversationTitle(conversation: Conversation, currentUserId: string): string {
    if (conversation.name) {
        return conversation.name;
    }

    return counterpart(conversation, currentUserId)?.name ?? 'Unknown';
}

export function counterpart(conversation: Conversation, currentUserId: string): Participant | null {
    return conversation.participants.find((p) => p.id !== currentUserId) ?? null;
}

/** Initials for the avatar fallback. Two letters at most, so the circle stays legible. */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]!.toUpperCase())
        .join('');
}

/**
 * List timestamps compress with age, the way a reader's attention does: a time
 * today, a weekday this week, a date beyond that.
 */
export function listTime(iso: string, now = new Date()): string {
    const at = new Date(iso);
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const daysAgo = Math.floor((startOfToday.getTime() - startOfDay(at).getTime()) / 86_400_000);

    if (daysAgo <= 0) return time(at);
    if (daysAgo === 1) return 'Yesterday';
    if (daysAgo < 7) return at.toLocaleDateString(undefined, { weekday: 'long' });

    return at.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

/** The divider between runs of messages: a date, spelled out. */
export function dayLabel(iso: string, now = new Date()): string {
    const at = new Date(iso);
    const daysAgo = Math.floor((startOfDay(now).getTime() - startOfDay(at).getTime()) / 86_400_000);

    if (daysAgo <= 0) return 'Today';
    if (daysAgo === 1) return 'Yesterday';
    if (daysAgo < 7) return at.toLocaleDateString(undefined, { weekday: 'long' });

    return at.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'long',
        ...(at.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
    });
}

export function time(at: Date): string {
    return at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
}

function startOfDay(at: Date): Date {
    return new Date(at.getFullYear(), at.getMonth(), at.getDate());
}

/**
 * Consecutive messages from one person in a short window read as one turn, so
 * only the first of a run carries an avatar and a name. Without this the thread
 * becomes a wall of repeated faces and stops scanning as a conversation.
 */
const RUN_WINDOW_MS = 5 * 60 * 1000;

export interface ThreadItem {
    message: Message;
    author: Participant | undefined;
    /** First message of this author's run — gets the avatar and the name. */
    startsRun: boolean;
    /** Last of the run — gets the timestamp and the receipt. */
    endsRun: boolean;
    /** Set when this message opens a new calendar day. */
    dayBreak: string | null;
}

export function buildThread(
    messages: Message[],
    participants: Participant[],
    now = new Date(),
): ThreadItem[] {
    const byId = new Map(participants.map((p) => [p.id, p]));

    return messages.map((message, i) => {
        const previous = messages[i - 1];
        const next = messages[i + 1];
        const newDay =
            !previous || startOfDay(new Date(previous.created_at)).getTime() !== startOfDay(new Date(message.created_at)).getTime();

        return {
            message,
            author: byId.get(message.user_id),
            startsRun: newDay || !previous || !sameRun(previous, message),
            endsRun: !next || !sameRun(message, next),
            dayBreak: newDay ? dayLabel(message.created_at, now) : null,
        };
    });
}

function sameRun(a: Message, b: Message): boolean {
    return (
        a.user_id === b.user_id &&
        new Date(b.created_at).getTime() - new Date(a.created_at).getTime() < RUN_WINDOW_MS
    );
}
