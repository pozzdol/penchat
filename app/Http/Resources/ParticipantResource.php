<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presence is never persisted: `online` is whatever the presence channel last
 * said. Until that channel exists (Phase 2) only the viewer is online.
 *
 * @mixin User
 */
class ParticipantResource extends JsonResource
{
    public function __construct(User $user, private bool $online = false)
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => null,
            'online' => $this->online,
        ];
    }
}
