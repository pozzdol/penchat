<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a message. Phase 2's MessageSent::broadcastWith() returns
 * exactly this, which is how resources/js/types/index.ts stays true.
 *
 * `readPointer` is the lowest last_read_message_id among the *other*
 * participants: a message is read by everyone once its id is at or below it.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function __construct(Message $message, private int $readPointer = 0)
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'attachments' => AttachmentResource::collection($this->attachments),
            'delivery' => $this->id <= $this->readPointer ? 'read' : 'sent',
        ];
    }
}
