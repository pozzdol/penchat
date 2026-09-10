<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The whole spam ladder, in one file, so the numbers are arguable in one
 * place rather than scattered through controllers.
 *
 * Escalating on purpose. A single burst — a long paste, an argument, a list
 * of links someone actually meant to send — must never suspend anybody. Only
 * a rate that keeps hitting the ceiling does, and even then it lifts itself.
 */
class SpamGuard
{
    /** One message every two seconds, sustained. Not reachable by hand. */
    private const PER_MINUTE = 30;

    /** How many times that ceiling may be hit before it stops being a mistake. */
    private const STRIKES = 3;

    /** How long strikes are remembered. The ladder forgives on its own. */
    private const STRIKE_MEMORY = 600;

    /** Minutes, in order. Staying past the end repeats the last one. */
    private const LADDER = [15, 60, 360, 1440];

    /**
     * Called before a message is written. Either it returns, or it throws the
     * field error the composer shows.
     *
     * @throws ValidationException
     */
    public static function check(User $user): void
    {
        $rate = "messages:{$user->id}";

        if (! RateLimiter::tooManyAttempts($rate, self::PER_MINUTE)) {
            RateLimiter::hit($rate, 60);

            return;
        }

        /*
         * At most one strike per minute. Without this, a single burst scores
         * a strike for every message past the ceiling — someone who paste-
         * sends forty lines once would collect ten strikes and be suspended
         * for a thing the ladder was explicitly designed to forgive. A strike
         * counts occasions, not rejected requests.
         */
        $cooldown = "strike-cooldown:{$user->id}";

        if (! RateLimiter::tooManyAttempts($cooldown, 1)) {
            RateLimiter::hit($cooldown, 60);
            RateLimiter::hit("strikes:{$user->id}", self::STRIKE_MEMORY);

            if (RateLimiter::attempts("strikes:{$user->id}") >= self::STRIKES) {
                self::suspend($user);
            }
        }

        throw ValidationException::withMessages([
            'body' => 'You are sending messages too quickly. Wait a moment.',
        ]);
    }

    /**
     * Each suspension is longer than the last, counted from how many the user
     * has collected while the memory lasts.
     */
    private static function suspend(User $user): void
    {
        $served = "suspensions:{$user->id}";
        RateLimiter::hit($served, 86400);

        $step = min(RateLimiter::attempts($served), count(self::LADDER)) - 1;

        $user->forceFill([
            'suspended_until' => now()->addMinutes(self::LADDER[$step]),
        ])->save();
    }
}
