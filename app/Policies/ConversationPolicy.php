<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Pivot membership is the only thing standing between users and each other's
 * chats. Every conversation route and, later, every channel callback comes here.
 *
 * Roles apply to groups only. A direct chat has two equals: `isAdmin` is never
 * consulted there, and the management abilities all refuse outright.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->participates($user, $conversation);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->participates($user, $conversation);
    }

    public function markRead(User $user, Conversation $conversation): bool
    {
        return $this->participates($user, $conversation);
    }

    /** Hiding your own copy of the history needs nothing but membership. */
    public function clearHistory(User $user, Conversation $conversation): bool
    {
        return $this->participates($user, $conversation);
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $this->participates($user, $conversation);
    }

    /**
     * Wiping a direct chat for both sides. Groups are left, not deleted, so
     * that one person cannot destroy everyone else's history.
     */
    public function deleteForEveryone(User $user, Conversation $conversation): bool
    {
        return ! $conversation->isGroup() && $this->participates($user, $conversation);
    }

    public function addMember(User $user, Conversation $conversation): bool
    {
        if (! $conversation->isGroup() || ! $this->participates($user, $conversation)) {
            return false;
        }

        return $conversation->isAdmin($user) || $conversation->members_can_add;
    }

    public function removeMember(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->isAdmin($user);
    }

    /**
     * The owner always may; admins only where the owner has delegated it. The
     * owner is never a valid target — that guard lives in the controller, which
     * knows who is being promoted.
     */
    public function manageAdmins(User $user, Conversation $conversation): bool
    {
        if (! $conversation->isGroup()) {
            return false;
        }

        if ($conversation->isOwner($user)) {
            return true;
        }

        return $conversation->admins_can_promote && $conversation->isAdmin($user);
    }

    /** Group name and the members_can_add switch. */
    public function updateSettings(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->isAdmin($user);
    }

    /** Delegating the owner's own authority stays with the owner. */
    public function updateOwnerSettings(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->isOwner($user);
    }

    public function transferOwnership(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->isOwner($user);
    }

    private function participates(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->whereKey($user->id)->exists();
    }
}
