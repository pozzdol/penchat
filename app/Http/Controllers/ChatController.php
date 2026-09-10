<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\ParticipantResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /** Newest 200 messages of the open conversation; history beyond that is a later phase. */
    private const THREAD_LIMIT = 200;

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    /** Route-level `can:view,conversation` has already run. */
    public function show(Request $request, Conversation $conversation): Response
    {
        return $this->render($request, $conversation);
    }

    private function render(Request $request, ?Conversation $requested): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Resolved lazily and memoised: a partial reload that asks only for
        // `messages` must not pay for the conversation list. Inertia skips a
        // prop closure entirely when it is not in the `only` set
        // (PropsResolver::resolveProps continues before resolveValue), but a
        // value computed here in the controller would already have cost us.
        $conversations = null;
        $lastMessages = null;

        $last = function () use (&$lastMessages, $user): Collection {
            return $lastMessages ??= $this->lastMessages($user);
        };

        $load = function () use (&$conversations, $last, $user): Collection {
            return $conversations ??= $user->conversations()
                ->with('participants')
                ->get()
                // A conversation the viewer deleted stays gone until it holds
                // something they have not already dismissed. That is the whole
                // difference between Delete chat and Clear history.
                ->reject(fn (Conversation $c) => $c->pivot->hidden_at !== null && ! $last()->has($c->id))
                ->sortByDesc(fn (Conversation $c) => $last()->get($c->id)?->created_at ?? $c->created_at)
                ->values();
        };

        /**
         * Only what was actually asked for.
         *
         * This used to fall back to the first conversation so the third pane
         * was never blank, and it made the props lie: at `/` the page said a
         * conversation was open when nobody had opened one. Mobile could not
         * reach the list, Back did nothing, and closing a room reopened it.
         * `ThreadEmpty` is the answer to an empty third pane — it always was.
         */
        $active = function () use ($load, $requested): ?Conversation {
            // Reuse the eager-loaded instance rather than the bare one from
            // route binding, so the pivot and participants are present.
            return $requested ? $load()->firstWhere('id', $requested->id) : null;
        };

        return Inertia::render('chat', [
            'current_user' => new ParticipantResource($user, online: true),

            'conversations' => function () use ($load, $last, $user) {
                $unread = $this->unreadCounts($user);

                return $load()
                    ->map(fn (Conversation $c) => new ConversationResource(
                        $c,
                        $user,
                        $unread[$c->id] ?? 0,
                        $last()->get($c->id),
                    ))
                    ->all();
            },

            'active_conversation_id' => fn () => $active()?->id,

            'messages' => function () use ($active, $user) {
                $conversation = $active();

                if (! $conversation) {
                    return [];
                }

                $read = $conversation->readPointerFor($user);
                $delivered = $conversation->deliveredPointerFor($user);

                return $this->visibleMessages($conversation, $user)
                    ->with(['attachments', 'replyTo.author'])
                    ->orderByDesc('id')
                    ->limit(self::THREAD_LIMIT)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn (Message $m) => new MessageResource($m, $read, $delivered))
                    ->all();
            },
        ]);
    }

    /**
     * Unread per conversation in one query: messages newer than the viewer's
     * read pointer, by someone else, grouped by conversation.
     *
     * @return Collection<int, int>
     */
    private function unreadCounts(User $user): Collection
    {
        return DB::table('messages as m')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'm.conversation_id')
                    ->where('cu.user_id', $user->id);
            })
            ->whereRaw("m.id > coalesce(cu.last_read_message_id, '')")
            ->whereRaw("m.id > coalesce(cu.cleared_up_to_message_id, '')")
            ->where('m.user_id', '!=', $user->id)
            // A tombstone has nothing left to read, and a message the viewer
            // hid should not keep summoning them back to the conversation.
            ->whereNull('m.deleted_at')
            ->whereNotExists($this->hiddenByViewer($user))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($n) => (int) $n);
    }

    /**
     * The messages of one conversation that this viewer can still see: newer
     * than their cleared pointer, and not hidden one at a time.
     *
     * Tombstones stay. A deleted message is still part of the thread; what it
     * lost is its text, not its place.
     *
     * @return HasMany<Message, Conversation>
     */
    private function visibleMessages(Conversation $conversation, User $user): HasMany
    {
        $cleared = (string) ($conversation->pivot->cleared_up_to_message_id ?? '');

        return $conversation->messages()
            ->where('id', '>', $cleared)
            ->whereNotExists($this->hiddenByViewer($user, 'messages.id'));
    }

    /**
     * The newest visible message of every conversation the viewer is in, keyed
     * by conversation.
     *
     * "The last message" stopped being a property of a conversation the moment
     * history became per-viewer, so `latestMessage()` could not survive: two
     * people looking at the same sidebar row can honestly see different
     * previews. Two queries — the ids, then the rows — rather than one per
     * conversation.
     *
     * @return Collection<string, Message>
     */
    private function lastMessages(User $user): Collection
    {
        $ids = DB::table('messages as m')
            ->join('conversation_user as cu', function ($join) use ($user) {
                $join->on('cu.conversation_id', '=', 'm.conversation_id')
                    ->where('cu.user_id', $user->id);
            })
            ->whereRaw("m.id > coalesce(cu.cleared_up_to_message_id, '')")
            ->whereNotExists($this->hiddenByViewer($user))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as conversation_id, max(m.id) as last_id')
            ->pluck('last_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Message::with(['attachments', 'replyTo.author'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('conversation_id');
    }

    /**
     * "This viewer hid this message." A correlated subquery rather than a left
     * join, so a conversation with a hundred hidden messages still yields one
     * row per message.
     */
    private function hiddenByViewer(User $user, string $messageId = 'm.id'): callable
    {
        return function ($query) use ($user, $messageId) {
            $query->select(DB::raw(1))
                ->from('message_user_deletions as d')
                ->whereColumn('d.message_id', $messageId)
                ->where('d.user_id', $user->id);
        };
    }
}
