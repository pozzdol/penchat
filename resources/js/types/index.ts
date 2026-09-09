/**
 * Wire shapes. These must match the `broadcastWith()` payloads on the server —
 * see the data model in AGENTS.md. Nothing here describes an Eloquent model;
 * it describes what actually crosses the socket.
 */

export type ConversationType = 'direct' | 'group';

/**
 * Presence is not persisted (AGENTS.md § transport routing), so `online` is
 * whatever the presence channel last said — never read it from the database.
 */
export interface Participant {
    id: number;
    name: string;
    avatar_url: string | null;
    online: boolean;
}

/**
 * `pending` and `failed` exist only on the client, between the optimistic
 * append and the HTTP response. The server never sends them.
 */
export type MessageDelivery = 'pending' | 'sent' | 'read' | 'failed';

export interface Attachment {
    id: number;
    original_name: string;
    mime: string;
    size: number;
    url: string;
}

export interface Message {
    id: number;
    conversation_id: number;
    user_id: number;
    body: string | null;
    created_at: string;
    attachments: Attachment[];
    delivery: MessageDelivery;
}

export interface Conversation {
    id: number;
    type: ConversationType;
    /** Null for a direct chat — the title is then the other participant's name. */
    name: string | null;
    participants: Participant[];
    last_message: Message | null;
    unread_count: number;
    /** Whether the current user is named in the unread run. Drives the blue mark. */
    mentioned: boolean;
}

export interface ChatPageProps {
    current_user: Participant;
    conversations: Conversation[];
    active_conversation_id: number | null;
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
