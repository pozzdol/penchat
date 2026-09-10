<?php

namespace App\Http\Resources;

use App\Enums\ConversationRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presence is never persisted (AGENTS.md § transport routing), so `online`
 * here is only a starting value: false for everyone but the viewer, who is
 * plainly reading the page. The `presence-online` channel is the source of
 * truth and the client overwrites this the moment it connects.
 *
 * `role` is null outside a group — a direct chat has two equals.
 *
 * @mixin User
 */
class ParticipantResource extends JsonResource
{
    public function __construct(
        User $user,
        private bool $online = false,
        private ?ConversationRole $role = null,
    ) {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => null,
            'online' => $this->online,
            'role' => $this->role?->value,
        ];
    }
}
