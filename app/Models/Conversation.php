<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Policies\ConversationPolicy;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A direct chat is a group with two participants. Only presentation differs.
 */
#[UsePolicy(ConversationPolicy::class)]
#[Fillable(['type', 'name', 'direct_key', 'created_by'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('last_read_message_id', 'joined_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Lowest read pointer among everyone except the viewer — a viewer's own
     * message is "read" once its id is at or below this. Requires loaded
     * participants; 0 when the viewer is alone.
     */
    public function readPointerFor(User $viewer): int
    {
        $others = $this->participants->reject(fn (User $u) => $u->is($viewer));

        return $others->isEmpty()
            ? 0
            : (int) $others->min(fn (User $u) => $u->pivot->last_read_message_id ?? 0);
    }

    public static function directKeyFor(User $a, User $b): string
    {
        return min($a->id, $b->id).'-'.max($a->id, $b->id);
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
                ['type' => ConversationType::Direct, 'created_by' => $a->id],
            );

            DB::table('conversation_user')->insertOrIgnore([
                ['conversation_id' => $conversation->id, 'user_id' => $a->id, 'joined_at' => now()],
                ['conversation_id' => $conversation->id, 'user_id' => $b->id, 'joined_at' => now()],
            ]);

            return $conversation;
        });
    }
}
