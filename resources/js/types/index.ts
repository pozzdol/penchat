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
 * `pending` and `failed` exist only on the client, between the optimistic
 * append and the HTTP response. The server never sends them.
 */
export type MessageDelivery = 'pending' | 'sent' | 'read' | 'failed';

export interface Attachment {
    id: string;
    original_name: string;
    mime: string;
    size: number;
    url: string;
}

export interface Message {
    id: string;
    conversation_id: string;
    user_id: string;
    body: string | null;
    created_at: string;
    attachments: Attachment[];
    delivery: MessageDelivery;
}

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
