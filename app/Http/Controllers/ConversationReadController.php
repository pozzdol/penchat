<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Http\Requests\MarkConversationReadRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ConversationReadController extends Controller
{
    /**
     * Move this participant's read pointer. Route-level `can:markRead` has run.
     *
     * The pointer only ever moves forward, and never past a message that
     * actually exists in this conversation — a client that sends a stale or
     * invented id cannot rewind it or skip ahead.
     */
    public function update(MarkConversationReadRequest $request, Conversation $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pivot = $conversation->participants()->whereKey($user->id)->first()?->pivot;

        $target = $conversation->messages()
            ->where('id', '<=', $request->validated('message_id'))
            ->max('id');

        // strcmp, not `>`: PHP would compare two all-digit ULIDs numerically.
        if ($target !== null && strcmp($target, (string) ($pivot?->last_read_message_id ?? '')) > 0) {
            $conversation->participants()->updateExistingPivot($user->id, [
                'last_read_message_id' => $target,
                // You cannot have read what never arrived. Letting the two
                // disagree would let a message report "read" while the tick
                // logic still called it merely delivered.
                'last_delivered_message_id' => $target,
            ]);

            // Only when it actually moved. A pointer that did not move changes
            // nobody's ticks, and firing anyway would have every open client
            // reload on every no-op mark-read.
            ConversationTouched::dispatch($conversation);
        }

        return back();
    }
}
