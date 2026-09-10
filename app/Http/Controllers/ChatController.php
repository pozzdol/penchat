<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\ParticipantResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
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
        $load = function () use (&$conversations, $user): Collection {
            return $conversations ??= $user->conversations()
                ->with(['participants', 'latestMessage.attachments'])
                ->get()
                ->sortByDesc(fn (Conversation $c) => $c->latestMessage?->created_at ?? $c->created_at)
                ->values();
        };

        $active = function () use ($load, $requested): ?Conversation {
            // Reuse the eager-loaded instance rather than the bare one from
            // route binding, so the pivot and participants are present.
            return $requested
                ? $load()->firstWhere('id', $requested->id)
                : $load()->first();
        };

        return Inertia::render('chat', [
            'current_user' => new ParticipantResource($user, online: true),

            'conversations' => function () use ($load, $user) {
                $unread = $this->unreadCounts($user);

                return $load()
                    ->map(fn (Conversation $c) => new ConversationResource($c, $user, $unread[$c->id] ?? 0))
                    ->all();
            },

            'active_conversation_id' => fn () => $active()?->id,

            'messages' => function () use ($active, $user) {
                $conversation = $active();

                if (! $conversation) {
                    return [];
                }

                $pointer = $conversation->readPointerFor($user);

                return $conversation->messages()
                    ->with('attachments')
                    ->orderByDesc('id')
                    ->limit(self::THREAD_LIMIT)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn (Message $m) => new MessageResource($m, $pointer))
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
            ->where('m.user_id', '!=', $user->id)
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id as conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($n) => (int) $n);
    }
}
