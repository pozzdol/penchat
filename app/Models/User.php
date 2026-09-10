<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * Three to twenty characters, starting with a letter. Lowercase only —
     * usernames are stored lowercase so the unique index is case-insensitive,
     * and every entry point normalises before it validates.
     */
    public const USERNAME_REGEX = '/^[a-z][a-z0-9_]{2,19}$/';

    /** Trim, drop a leading @, lowercase. Shared by every field that takes one. */
    public static function normalizeUsername(mixed $value): string
    {
        return Str::lower(ltrim(trim((string) $value), '@'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Sign-in is passwordless, so this column is always null. The remember-me
     * cookie still HMACs "the password" (SessionGuard::hashPasswordForCookie),
     * and hash_hmac(null) is a deprecation on every login — hand it a string.
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot('role', 'last_read_message_id', 'cleared_up_to_message_id', 'joined_at');
    }
}
