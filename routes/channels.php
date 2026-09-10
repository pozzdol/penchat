<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| This file is the security boundary of the app. A callback that returns true
| without checking anything hands one user another user's conversations.
|
| **Never cast an id.** The scaffolding this replaced compared users with
| `(int) $user->id === (int) $id`, which is correct for auto-increment keys and
| catastrophic for ours: `(int) '01m24h96p03yag6b6zh9tm1h7a'` is 1, and so is
| every other ULID minted this decade, so the check passed for everyone. Ids
| are strings here and are compared as strings, with `===`.
|
*/

/**
 * Everything that happens inside one conversation: new messages, edits,
 * tombstones, and typing whispers.
 *
 * Membership is read from the pivot, which is the same thing ConversationPolicy
 * checks. A former member who has left keeps nothing.
 */
Broadcast::channel('conversation.{conversationId}', function (User $user, string $conversationId): bool {
    return Conversation::whereKey($conversationId)
        ->whereHas('participants', fn ($q) => $q->whereKey($user->id))
        ->exists();
});

/**
 * One participant's own line. It carries only "something changed in
 * conversation X" — never content — so that the sidebar can refresh without
 * subscribing to every conversation separately.
 */
Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return $user->id === $userId;
});

/**
 * Who is online, app-wide. Presence is never persisted (AGENTS.md § transport
 * routing): this roster exists only for as long as the sockets do.
 *
 * The payload is deliberately thin. It is broadcast to everyone signed in, so
 * it carries what the green dot needs to find its avatar and nothing else.
 *
 * @return array{id: string, name: string}
 */
Broadcast::channel('online', function (User $user): array {
    return ['id' => $user->id, 'name' => $user->name];
});
