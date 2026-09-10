<?php

namespace App\Models;

use App\Enums\ConversationRole;
use App\Enums\ConversationType;
use App\Policies\ConversationPolicy;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A direct chat is a group with two participants. Only presentation differs.
 */
#[UsePolicy(ConversationPolicy::class)]
#[Fillable(['type', 'name', 'direct_key', 'owner_id', 'admins_can_promote', 'members_can_add'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            // A group name says plenty on its own next to a participant list.
            'name' => 'encrypted',
            'admins_can_promote' => 'boolean',
            'members_can_add' => 'boolean',
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'last_read_message_id', 'last_delivered_message_id', 'cleared_up_to_message_id', 'hidden_at', 'joined_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Null on a direct chat: two equals, nobody in charge. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isGroup(): bool
    {
        return $this->type === ConversationType::Group;
    }

    public function isOwner(User $user): bool
    {
        return $this->owner_id !== null
            && $this->owner_id === $user->id
            && $this->roleOf($user) !== null;
    }

    /**
     * Requires the pivot to be loaded for this user — either through
     * `participants` or the `conversations` relation on User.
     */
    public function roleOf(User $user): ?ConversationRole
    {
        $pivot = $this->relationLoaded('participants')
            ? $this->participants->firstWhere('id', $user->id)?->pivot
            : $this->participants()->whereKey($user->id)->first()?->pivot;

        return $pivot ? ConversationRole::from($pivot->role) : null;
    }

    /**
     * Membership is checked first on purpose: without it an owner_id pointing
     * at someone with no pivot row — removed, left, or never attached — would
     * clear every admin gate in the policy.
     */
    public function isAdmin(User $user): bool
    {
        $role = $this->roleOf($user);

        if ($role === null) {
            return false;
        }

        return $role === ConversationRole::Admin || $this->owner_id === $user->id;
    }

    /**
     * Lowest read pointer among everyone except the viewer — a viewer's own
     * message is "read" once its id is at or below this. Requires loaded
     * participants.
     *
     * Returns '' for "nobody has read anything", which is what 0 meant while
     * the keys were integers: the empty string sorts below every ULID.
     */
    public function readPointerFor(User $viewer): string
    {
        return $this->lowestPointerAmongOthers($viewer, 'last_read_message_id');
    }

    /**
     * The same shape one step earlier: a message is on everyone's device once
     * its id is at or below this. Two grey ticks; green needs the pointer
     * above.
     */
    public function deliveredPointerFor(User $viewer): string
    {
        return $this->lowestPointerAmongOthers($viewer, 'last_delivered_message_id');
    }

    /**
     * Lowest wins, so a group only advances at the pace of whoever is furthest
     * behind — one member who has not caught up holds the mark back, which is
     * exactly what makes the tick trustworthy.
     */
    private function lowestPointerAmongOthers(User $viewer, string $column): string
    {
        $others = $this->participants->reject(fn (User $u) => $u->is($viewer));

        if ($others->isEmpty()) {
            return '';
        }

        return $others
            ->map(fn (User $u) => (string) ($u->pivot->{$column} ?? ''))
            ->sort(fn (string $a, string $b) => strcmp($a, $b))
            ->first();
    }

    /**
     * Empty this conversation for one participant.
     *
     * Both pointers move. Setting only the cleared pointer would leave an
     * unread badge counting messages the viewer can no longer reach, and no
     * way to clear it.
     *
     * `$hide` is the whole difference between Clear history and Delete chat:
     * hidden takes the row off the list until somebody writes again. Clearing
     * without it nulls the marker, so the two can never contradict each other.
     */
    public function clearFor(User $user, bool $hide = false): void
    {
        $upTo = $this->messages()->max('id');

        $this->participants()->updateExistingPivot($user->id, [
            'cleared_up_to_message_id' => $upTo,
            'last_read_message_id' => $upTo,
            'hidden_at' => $hide ? now() : null,
        ]);

        $this->unsetRelation('participants');
    }

    /**
     * Everyone who joins starts from the present. Both pointers are set to the
     * newest message so a new member does not open the group to a wall of
     * history marked unread, and someone re-added after leaving gets a clean
     * slate rather than the backlog they walked away from.
     *
     * @param  list<User>  $users
     */
    public function attachParticipants(array $users, ConversationRole $role = ConversationRole::Member): void
    {
        if ($users === []) {
            return;
        }

        $from = $this->messages()->max('id');

        $this->participants()->syncWithoutDetaching(
            collect($users)->mapWithKeys(fn (User $u) => [$u->id => [
                'role' => $role->value,
                'joined_at' => now(),
                'last_read_message_id' => $from,
                'last_delivered_message_id' => $from,
                'cleared_up_to_message_id' => $from,
            ]])->all(),
        );

        $this->unsetRelation('participants');
    }

    /**
     * The one place a group is born, so `owner_id` and the owner's admin pivot
     * row can never disagree.
     *
     * @param  list<User>  $members
     */
    public static function createGroup(string $name, User $owner, array $members = []): self
    {
        return DB::transaction(function () use ($name, $owner, $members) {
            $conversation = static::create([
                'type' => ConversationType::Group,
                'name' => $name,
                'owner_id' => $owner->id,
            ]);

            $conversation->attachParticipants([$owner], ConversationRole::Admin);
            $conversation->attachParticipants(
                collect($members)->reject(fn (User $u) => $u->is($owner))->values()->all(),
            );

            return $conversation;
        });
    }

    /**
     * Remove a participant, and hand the group on if that participant owned it.
     *
     * Succession goes to the longest-standing admin and, failing that, promotes
     * the longest-standing member. `joined_at` is second-precision and a group
     * created in one request gives everyone the same value, so ties are the
     * common case rather than an exotic one — `user_id` is what actually
     * decides most of the time, and it makes the outcome reproducible.
     */
    public function removeParticipant(User $user): void
    {
        DB::transaction(function () use ($user) {
            $wasOwner = $this->owner_id === $user->id;

            $this->participants()->detach($user->id);
            $this->unsetRelation('participants');

            if (! $wasOwner) {
                return;
            }

            $heir = $this->participants()
                ->orderByRaw('case when conversation_user.role = ? then 0 else 1 end', [ConversationRole::Admin->value])
                ->orderBy('conversation_user.joined_at')
                ->orderBy('users.id')
                ->first();

            if (! $heir) {
                // Nobody left to inherit it. Messages cascade.
                $this->delete();

                return;
            }

            $this->participants()->updateExistingPivot($heir->id, ['role' => ConversationRole::Admin->value]);
            $this->forceFill(['owner_id' => $heir->id])->save();
            $this->unsetRelation('participants');
        });
    }

    /**
     * Ordered so both sides derive the same key. strcmp rather than min/max:
     * PHP compares two numeric-looking strings numerically, and a ULID that
     * happened to be all digits would order wrongly.
     */
    public static function directKeyFor(User $a, User $b): string
    {
        return strcmp($a->id, $b->id) <= 0
            ? $a->id.'-'.$b->id
            : $b->id.'-'.$a->id;
    }

    /**
     * Find or create the direct chat between two users as a single race-safe
     * operation. `createOrFirst` inserts and, on a unique violation, re-selects —
     * inside a savepoint when a transaction is open, so a losing insert does not
     * abort the enclosing Postgres transaction. The pivot rows go in with
     * "on conflict do nothing", so a second caller never resets `joined_at`.
     */
    public static function findOrCreateDirect(User $a, User $b): self
    {
        if ($a->is($b)) {
            throw new InvalidArgumentException('A direct conversation needs two different users.');
        }

        return DB::transaction(function () use ($a, $b) {
            $conversation = static::createOrFirst(
                ['direct_key' => static::directKeyFor($a, $b)],
                ['type' => ConversationType::Direct, 'owner_id' => null],
            );

            DB::table('conversation_user')->insertOrIgnore([
                ['conversation_id' => $conversation->id, 'user_id' => $a->id, 'joined_at' => now()],
                ['conversation_id' => $conversation->id, 'user_id' => $b->id, 'joined_at' => now()],
            ]);

            return $conversation;
        });
    }
}
