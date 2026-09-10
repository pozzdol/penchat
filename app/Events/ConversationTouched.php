<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Something about this conversation changed for you." Carries no content.
 *
 * It goes to each participant's own line rather than to the conversation
 * channel, because someone who does not have that conversation open is not
 * subscribed to it — and their sidebar badge still has to move. One
 * subscription per person covers every conversation they are in.
 *
 * Thin on purpose. `unread_count`, the list ordering and the sidebar preview
 * are all per-viewer since Phase 1c/1d, so there is no single correct payload
 * to send. The client answers with a partial reload; that is not a shortcut,
 * it is the only honest answer.
 */
class ConversationTouched implements ShouldBroadcastNow
{
    /** Carried so `->toOthers()` is available and honest if it is ever used. */
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * `$alsoTell` is for people who have just stopped being participants. A
     * removed member has to lose the row from their sidebar, and by the time
     * this fires their pivot row is already gone — so they cannot be found by
     * the query below and have to be named explicitly.
     *
     * @param  list<string>  $alsoTell  user ids
     */
    public function __construct(
        public Conversation $conversation,
        public array $alsoTell = [],
    ) {}

    public function broadcastAs(): string
    {
        return 'conversation.touched';
    }

    /**
     * Every current participant, resolved at dispatch time.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return $this->conversation->participants()
            ->pluck('users.id')
            ->merge($this->alsoTell)
            ->unique()
            ->map(fn (string $id) => new PrivateChannel("user.{$id}"))
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->id];
    }
}
