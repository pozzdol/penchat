<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Membership is checked here too, not just on the conversation: a message id
 * is the only thing these routes take, so the conversation it belongs to has
 * to be reached through it.
 */
class MessagePolicy
{
    /** Fixing a typo, not rewriting history: the author, inside the window. */
    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $message->isEditable()
            && $this->participates($user, $message);
    }

    /**
     * The author at any time, or an admin of the group it was posted in. A
     * direct chat has no admin, so there only the author may.
     */
    public function deleteForEveryone(User $user, Message $message): bool
    {
        if ($message->isDeleted() || ! $this->participates($user, $message)) {
            return false;
        }

        return $message->user_id === $user->id
            || Gate::forUser($user)->allows('deleteAnyMessage', $message->conversation);
    }

    /** Hiding your own copy of someone else's words needs only membership. */
    public function deleteForMe(User $user, Message $message): bool
    {
        return $this->participates($user, $message);
    }

    private function participates(User $user, Message $message): bool
    {
        return $message->conversation->participants()->whereKey($user->id)->exists();
    }
}
