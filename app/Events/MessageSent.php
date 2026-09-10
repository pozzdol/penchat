<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was written. **New messages only** — edits and tombstones go out
 * as `ConversationTouched` instead, and this is a correctness rule, not a
 * preference.
 *
 * A payload on a channel is the same for every subscriber, but what a
 * participant may *see* is not: `cleared_up_to_message_id` and
 * `message_user_deletions` are per-viewer. Broadcasting an edit therefore
 * pushes the new text of a message straight past the filter that was hiding
 * it — someone who chose "delete for me" watches it reappear the moment its
 * author fixes a typo. A message that has just been created cannot be caught
 * by either filter: nobody can have hidden an id that did not exist, and its
 * ULID is above every cleared pointer. So this one payload is always safe,
 * and everything else takes the round-trip and lets the server decide.
 *
 * The client still upserts by id rather than appending, because a sender can
 * receive its own message through both the HTTP response and the socket.
 *
 * `ShouldBroadcastNow`, not `ShouldBroadcast`: `QUEUE_CONNECTION` is `database`
 * and no worker runs in development, so a queued broadcast would be swallowed
 * in silence. At five users the send is a local call and costs nothing.
 */
class MessageSent implements ShouldBroadcastNow
{
    /**
     * `InteractsWithSockets` is what gives `->toOthers()` something to act on.
     * Without it the call is silently inert — no error, just the author
     * receiving a copy of their own message back over the socket.
     */
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * A name of our own, so the wire format does not quietly depend on a PHP
     * namespace. The client listens for `.message.sent` — the leading dot is
     * Echo's way of saying "this name is already complete".
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    /**
     * The wire shape is `MessageResource`, which is what
     * `resources/js/types/index.ts` already describes — the resource's docblock
     * promised this and now it is true. Never the model: that would leak
     * columns and tie the wire format to the schema.
     *
     * `delivery` is deliberately absent. It is per-viewer, computed from a read
     * pointer that differs for everyone on the channel, so there is no single
     * honest value to send. The client keeps what it has until the next
     * sidebar refresh corrects it.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = (new MessageResource($this->message->loadMissing(['attachments', 'replyTo.author'])))
            ->toArray(request());

        unset($payload['delivery']);

        return ['message' => $payload];
    }
}
