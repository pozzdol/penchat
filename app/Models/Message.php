<?php

namespace App\Models;

use App\Policies\MessagePolicy;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(MessagePolicy::class)]
#[Fillable(['conversation_id', 'user_id', 'body', 'reply_to_message_id', 'reply_to_body', 'created_at', 'edited_at', 'deleted_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    /** Messages are never "updated" in the Eloquent sense; edits set `edited_at`. */
    const UPDATED_AT = null;

    /**
     * How long an author may still fix a typo. Anchored on `created_at`, never
     * on `edited_at` — otherwise each edit would buy another two hours and the
     * window would never close.
     */
    public const EDIT_WINDOW_MINUTES = 120;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Encrypted at rest: a leaked dump is not a leaked conversation.
            // Nothing queries `body` in SQL — search runs in the browser — so
            // making it opaque to Postgres costs nothing here.
            'body' => 'encrypted',
            // A quote is message content too. If `body` has to be unreadable
            // in a leaked dump, so does the copy of it sitting in a reply.
            'reply_to_body' => 'encrypted',
            'created_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * A deleted message keeps its row. `deleted_at` is a tombstone, not a soft
     * delete: the thread still shows "This message was deleted" where it stood,
     * because a message vanishing mid-conversation reads as a bug. Do not add
     * the SoftDeletes trait — it would hide the row from every query.
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isEditable(): bool
    {
        return ! $this->isDeleted()
            && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }

    /**
     * The message this one quotes, if any.
     *
     * Only ever read for the original author's name and for whether it has
     * since been deleted — the quoted *words* live on this row, frozen at the
     * moment reply was pressed.
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
