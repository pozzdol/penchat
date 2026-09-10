/**
 * Wire shapes. These must match the `broadcastWith()` payloads on the server —
 * see the data model in AGENTS.md. Nothing here describes an Eloquent model;
 * it describes what actually crosses the socket.
 */

export type ConversationType = 'direct' | 'group';

/** Roles exist only inside groups; a direct chat has two equals. */
export type ConversationRole = 'admin' | 'member';

/**
 * Presence is not persisted (AGENTS.md § transport routing), so `online` is
 * whatever the presence channel last said — never read it from the database.
 */
export interface Participant {
    id: string;
    name: string;
    /** The handle others type to reach this person. Null only for pre-username rows. */
    username: string | null;
    /** Null outside a group. */
    role: ConversationRole | null;
    avatar_url: string | null;
    online: boolean;
}

/**
 * Four server states in order — `sent` (we have it), `delivered` (every other
 * device has it), `read` (everyone has opened it) — plus `pending` and
 * `failed`, which exist only on the client between the optimistic append and
 * the HTTP response. The server never sends those two.
 */
export type MessageDelivery = 'pending' | 'sent' | 'delivered' | 'read' | 'failed';

export interface Attachment {
    id: string;
    original_name: string;
    mime: string;
    size: number;
    url: string;
}

/**
 * The message a reply quotes.
 *
 * `body` is a snapshot frozen when reply was pressed, so it survives the
 * original being edited or deleted. `author` and `deleted` come from the
 * original, which always exists — deleting leaves a tombstone.
 */
export interface MessageQuote {
    id: string;
    author: string | null;
    body: string | null;
    deleted: boolean;
}

export interface Message {
    id: string;
    conversation_id: string;
    user_id: string;
    /** Null on a tombstone: deleting drops the text, it does not hide it. */
    body: string | null;
    created_at: string;
    edited_at: string | null;
    /**
     * Set once the message was deleted for everyone. The message keeps its
     * place in the thread — a message vanishing mid-conversation reads as a
     * bug — but there is nothing left in it.
     */
    deleted_at: string | null;
    reply_to: MessageQuote | null;
    attachments: Attachment[];
    delivery: MessageDelivery;
}

/** Mirrors Message::EDIT_WINDOW_MINUTES. The server is the one that enforces it. */
export const EDIT_WINDOW_MS = 120 * 60 * 1000;

/**
 * What the viewer is allowed to do, decided by the server. The panel renders
 * from these rather than re-deriving the rules here, so there is one copy of
 * the matrix and it is the one actually enforced.
 */
export interface ConversationAbilities {
    add_member: boolean;
    remove_member: boolean;
    manage_admins: boolean;
    update_settings: boolean;
    update_owner_settings: boolean;
    transfer_ownership: boolean;
    leave: boolean;
    /** Direct chats only — a group is left, not deleted. */
    delete_chat: boolean;
    /** Deleting other people's messages: a group admin, nobody else. */
    delete_any_message: boolean;
}

export interface Conversation {
    id: string;
    type: ConversationType;
    /** Null for a direct chat — the title is then the other participant's name. */
    name: string | null;
    /** Null on a direct chat: nobody is in charge of a conversation between two equals. */
    owner_id: string | null;
    members_can_add: boolean;
    admins_can_promote: boolean;
    viewer_role: ConversationRole | null;
    participants: Participant[];
    last_message: Message | null;
    unread_count: number;
    /** Whether the current user is named in the unread run. Drives the blue mark. */
    mentioned: boolean;
    can: ConversationAbilities;
}

/**
 * Shared with every page by `HandleInertiaRequests::share()`.
 *
 * `vapidPublicKey` is null when Web Push has no keys configured, which is how
 * the client knows to render no bell rather than one that cannot work.
 */
export interface SharedProps {
    vapidPublicKey: string | null;
    [key: string]: unknown;
}

export interface ChatPageProps {
    current_user: Participant;
    conversations: Conversation[];
    active_conversation_id: string | null;
    messages: Message[];
    [key: string]: unknown;
}

export type LoginStep = 'email' | 'code' | 'name';

export interface LoginPageProps {
    step: LoginStep;
    /** The address a code was sent to, or the verified address awaiting a name. */
    email: string | null;
    [key: string]: unknown;
}
