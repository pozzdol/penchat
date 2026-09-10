<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    /**
     * "Everything you have sent me is on my device now."
     *
     * Bulk and bodyless on purpose. The sidebar payload already carries the
     * newest message of *every* conversation, so by the time a page has
     * rendered the device genuinely holds all of them — acking one
     * conversation at a time would be one request per row for no more truth.
     *
     * No policy: it only ever writes the caller's own pivot rows, and it can
     * only move them to ids that already exist in conversations they are in.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $newest = DB::table('messages')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'messages.conversation_id')
                    ->where('cu.user_id', $user->id);
            })
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as id, max(messages.id) as newest')
            ->pluck('newest', 'id');

        $moved = [];

        foreach ($newest as $conversationId => $target) {
            // strcmp, never `>`: two all-digit ULIDs would compare numerically.
            $affected = DB::table('conversation_user')
                ->where('conversation_id', $conversationId)
                ->where('user_id', $user->id)
                ->whereRaw("coalesce(last_delivered_message_id, '') < ?", [$target])
                ->update(['last_delivered_message_id' => $target]);

            if ($affected > 0) {
                $moved[] = $conversationId;
            }
        }

        /*
         * Only for pointers that actually moved, and this is what stops an
         * infinite loop rather than merely saving a request. Advancing a
         * delivered pointer changes other people's ticks, so it has to be
         * announced — and their clients ack when they hear it. A tells B, B
         * tells A, forever. Because a second ack moves nothing, the exchange
         * dies out after one round.
         */
        if ($moved !== []) {
            Conversation::whereIn('id', $moved)
                ->get()
                ->each(fn (Conversation $c) => ConversationTouched::dispatch($c));
        }

        return back();
    }
}
