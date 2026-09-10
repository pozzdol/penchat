<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a message. Phase 2's MessageSent::broadcastWith() returns
 * exactly this, which is how resources/js/types/index.ts stays true.
 *
 * The two pointers are the lowest among the *other* participants, so a group
 * only advances at the pace of whoever is furthest behind. Order matters:
 * `read` is checked first because it implies `delivered`.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public function __construct(
        Message $message,
        private string $readPointer = '',
        private string $deliveredPointer = '',
    ) {
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
            'edited_at' => $this->edited_at?->toIso8601String(),
            // The tombstone. Deleting nulls the body on the way out, so there
            // is nothing here to leak even if a client ignored this flag.
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            // The quoted words are this row's own frozen copy; the author and
            // the tombstone state come from the original, which always exists
            // because deleting a message never removes it.
            'reply_to' => $this->reply_to_message_id === null ? null : [
                'id' => $this->reply_to_message_id,
                'author' => $this->replyTo?->author?->name,
                'body' => $this->reply_to_body,
                'deleted' => $this->replyTo?->isDeleted() ?? false,
            ],
            'attachments' => AttachmentResource::collection($this->attachments),
            'delivery' => match (true) {
                strcmp($this->id, $this->readPointer) <= 0 => 'read',
                strcmp($this->id, $this->deliveredPointer) <= 0 => 'delivered',
                default => 'sent',
            },
        ];
    }
}
