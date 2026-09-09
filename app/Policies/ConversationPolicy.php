<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Pivot membership is the only thing standing between users and each other's
 * chats. Every conversation route and, later, every channel callback comes here.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->whereKey($user->id)->exists();
    }
}
