<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

class ProfileController extends Controller
{
    /**
     * Your name and handle, as everyone else sees them.
     *
     * Behind `not-suspended`, and that is reading the existing rule rather
     * than inventing one: a suspension stops you putting content in front of
     * other people, and your name sits in every participant's sidebar.
     * Renaming yourself to something abusive would otherwise be a way around
     * it. Theme and signing out stay open, because those are only yours.
     */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $before = [$user->name, $user->username];

        $user->update($request->safe()->only(['name', 'username']));

        // Only when something actually moved. A pointer that did not move
        // announces nothing, and the same applies to a name: saving a form
        // unchanged must not wake every client this person shares a room
        // with.
        if ($before !== [$user->name, $user->username]) {
            $this->announce($user->id);
        }

        return back();
    }

    /**
     * A rename has to reach the sidebars it appears in, or everyone else
     * carries the old name until they happen to reload — the exact "refresh
     * before it shows up" behaviour this app is built to avoid.
     *
     * `ConversationTouched` and not a payload: what each participant may see
     * of a conversation is per-viewer, so the thin signal plus a reload is
     * the only honest answer (AGENTS.md § Backend conventions). Quoted
     * messages pick the new name up for free — `reply_to_body` snapshots the
     * words but never the author, precisely so a rename is not frozen into
     * somebody else's message.
     */
    private function announce(string $userId): void
    {
        Conversation::query()
            ->whereHas('participants', fn ($q) => $q->whereKey($userId))
            ->get()
            ->each(fn (Conversation $c) => ConversationTouched::dispatch($c));
    }
}
