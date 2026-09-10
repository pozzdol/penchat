<?php

namespace App\Http\Resources;

use App\Enums\ConversationRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

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
        $isGroup = $this->resource->isGroup();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'members_can_add' => $this->members_can_add,
            'admins_can_promote' => $this->admins_can_promote,
            'viewer_role' => $this->resource->roleOf($this->viewer)?->value,
            'participants' => $this->participants
                ->map(fn (User $u) => new ParticipantResource(
                    $u,
                    online: $u->is($this->viewer),
                    role: $isGroup ? ConversationRole::from($u->pivot->role) : null,
                ))
                ->all(),
            'last_message' => $this->latestMessage
                ? new MessageResource($this->latestMessage, $pointer)
                : null,
            'unread_count' => $this->unreadCount,
            // ponytail: static until unread bodies are scanned for @name.
            'mentioned' => false,
            // The panel renders from these rather than re-deriving the rules in
            // TypeScript, so there is one copy of the matrix and it is the one
            // the server actually enforces.
            'can' => [
                'add_member' => Gate::forUser($this->viewer)->allows('addMember', $this->resource),
                'remove_member' => Gate::forUser($this->viewer)->allows('removeMember', $this->resource),
                'manage_admins' => Gate::forUser($this->viewer)->allows('manageAdmins', $this->resource),
                'update_settings' => Gate::forUser($this->viewer)->allows('updateSettings', $this->resource),
                'update_owner_settings' => Gate::forUser($this->viewer)->allows('updateOwnerSettings', $this->resource),
                'transfer_ownership' => Gate::forUser($this->viewer)->allows('transferOwnership', $this->resource),
                'leave' => Gate::forUser($this->viewer)->allows('leave', $this->resource),
            ],
        ];
    }
}
