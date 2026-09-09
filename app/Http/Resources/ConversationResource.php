<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `participants` and `latestMessage.attachments` to be eager-loaded.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function __construct(Conversation $conversation, private User $viewer, private int $unreadCount)
    {
        parent::__construct($conversation);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pointer = $this->resource->readPointerFor($this->viewer);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'participants' => $this->participants
                ->map(fn (User $u) => new ParticipantResource($u, online: $u->is($this->viewer)))
                ->all(),
            'last_message' => $this->latestMessage
                ? new MessageResource($this->latestMessage, $pointer)
                : null,
            'unread_count' => $this->unreadCount,
            // ponytail: static until unread bodies are scanned for @name.
            'mentioned' => false,
        ];
    }
}
