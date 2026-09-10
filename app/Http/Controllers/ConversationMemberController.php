<?php

namespace App\Http\Controllers;

use App\Enums\ConversationRole;
use App\Http\Requests\AddMembersRequest;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Support\UsernameResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConversationMemberController extends Controller
{
    /** Route-level `can:addMember,conversation` has run. */
    public function store(AddMembersRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->groupOnly($conversation);

        $users = UsernameResolver::resolve($request->validated('usernames'));

        $already = $conversation->participants()
            ->whereIn('users.id', $users->pluck('id'))
            ->pluck('users.username');

        if ($already->isNotEmpty()) {
            throw ValidationException::withMessages([
                'usernames' => '@'.$already->first().' is already in this group.',
            ]);
        }

        $conversation->attachParticipants($users->all());

        return back();
    }

    /** Promote or demote. Route-level `can:manageAdmins,conversation` has run. */
    public function update(UpdateMemberRoleRequest $request, Conversation $conversation, User $user): RedirectResponse
    {
        $this->groupOnly($conversation);
        $this->mustBeMember($conversation, $user);

        if ($conversation->owner_id === $user->id) {
            // The owner is the one guaranteed admin; demoting them is what
            // would let a group end up with nobody in charge.
            throw ValidationException::withMessages([
                'role' => 'The owner’s role cannot be changed.',
            ]);
        }

        $conversation->participants()->updateExistingPivot($user->id, [
            'role' => $request->validated('role'),
        ]);

        return back();
    }

    /** Route-level `can:removeMember,conversation` has run. */
    public function destroy(Conversation $conversation, User $user): RedirectResponse
    {
        $this->groupOnly($conversation);
        $this->mustBeMember($conversation, $user);

        if ($conversation->owner_id === $user->id) {
            throw ValidationException::withMessages([
                'member' => 'The owner cannot be removed. They have to leave, or hand the group on first.',
            ]);
        }

        if ($user->is(request()->user())) {
            // One exit path, so the succession rule lives in one place.
            throw ValidationException::withMessages([
                'member' => 'Use “Exit group” to leave.',
            ]);
        }

        $conversation->removeParticipant($user);

        return back();
    }

    /** Hand the group to someone else. Route-level `can:transferOwnership` has run. */
    public function transfer(Conversation $conversation, User $user): RedirectResponse
    {
        $this->groupOnly($conversation);
        $this->mustBeMember($conversation, $user);

        if ($user->is(request()->user())) {
            throw ValidationException::withMessages(['member' => 'You already own this group.']);
        }

        // The new owner must be an admin, or the invariant "the owner is always
        // an admin" breaks the moment the handover lands.
        $conversation->participants()->updateExistingPivot($user->id, [
            'role' => ConversationRole::Admin->value,
        ]);
        $conversation->forceFill(['owner_id' => $user->id])->save();

        return back();
    }

    /** Leaving is always allowed; succession is handled by the model. */
    public function leave(Conversation $conversation): RedirectResponse
    {
        Gate::authorize('leave', $conversation);

        $conversation->removeParticipant(request()->user());

        return to_route('chat.index');
    }

    private function groupOnly(Conversation $conversation): void
    {
        abort_unless($conversation->isGroup(), 403);
    }

    private function mustBeMember(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->participants()->whereKey($user->id)->exists(), 404);
    }
}
