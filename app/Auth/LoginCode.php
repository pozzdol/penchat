<?php

namespace App\Auth;

use App\Mail\LoginCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One-time sign-in codes. Registration is open to any email, so the request
 * limits below are what stop this app from being used to flood inboxes.
 *
 * Codes live in the cache, hashed with an HMAC over APP_KEY. The keyspace is
 * only 10^6, so bcrypt would buy nothing against someone who can read the
 * cache table — an offline brute force finishes in hours at any cost factor,
 * versus never without the key for HMAC. hash_equals keeps the compare
 * constant-time.
 */
final class LoginCode
{
    private const TTL_MINUTES = 10;

    private const WINDOW_SECONDS = 600;

    private const SENDS_PER_EMAIL = 3;

    private const SENDS_PER_IP = 10;

    private const VERIFY_ATTEMPTS = 5;

    /**
     * Failed guesses one address may make, across every account, per window.
     *
     * This exists because burning a code after five wrong guesses — right as
     * a brute-force defence — is also a way to destroy somebody else's code
     * on demand. Anyone can put a victim's address into their own session and
     * guess five times; without a cost to the guesser, that locks a known user
     * out for as long as the attacker keeps it up.
     *
     * Ten is not arbitrary. It is deliberately below
     * SENDS_PER_EMAIL * VERIFY_ATTEMPTS (15) — the budget needed to burn every
     * code a person is allowed to ask for in one window — so a single address
     * can never take away all three of someone's attempts to sign in. Only
     * failures count, so a room of people signing in normally never reaches it.
     */
    private const VERIFY_ATTEMPTS_PER_IP = 10;

    /**
     * @throws ValidationException when a limit is hit or the mail cannot go out
     */
    public function send(string $email, string $ip): void
    {
        $limits = [
            'otp-send:email:'.$this->digest($email) => self::SENDS_PER_EMAIL,
            'otp-send:ip:'.$ip => self::SENDS_PER_IP,
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->refuse('email', 'Too many codes requested.', $key);
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($email), $this->hash($code), now()->addMinutes(self::TTL_MINUTES));

        /*
         * A new code gets a new five guesses. Without this the counter is a
         * ten-minute lock on the *address* rather than on the code: five wrong
         * guesses and every later attempt is refused on sight, so a stranger
         * could freeze someone out of their own account by guessing five times
         * and then simply waiting. Safe because asking for a code is itself
         * limited — three per address per window puts the ceiling at fifteen
         * guesses out of a million.
         */
        RateLimiter::clear($this->attemptKey($email));

        try {
            Mail::to($email)->send(new LoginCodeMail($code));
        } catch (Throwable $e) {
            // Never surface transport details to the browser.
            Log::error('Login code could not be sent.', ['exception' => $e]);
            $this->burn($email);

            throw ValidationException::withMessages([
                'email' => 'We could not send the code right now. Try again in a moment.',
            ]);
        }
    }

    /**
     * Single use: a correct code is destroyed on success. Five wrong guesses
     * destroy it too, so a code can never be attacked past 5 / 10^6.
     *
     * @throws ValidationException when the guesser has run out of attempts
     */
    public function verify(string $email, string $code, string $ip): bool
    {
        $perIp = 'otp-verify:ip:'.$ip;

        /*
         * Checked first, and it must stay first: everything below this can
         * destroy the code, and a limit that destroys the code is the very
         * lockout it was added to prevent. Refused, not answered `false` —
         * the guesser is being told about their own rate, which reveals
         * nothing about the code, and a wrong-code message here would send a
         * real person hunting for a typo that is not there.
         */
        if (RateLimiter::tooManyAttempts($perIp, self::VERIFY_ATTEMPTS_PER_IP)) {
            $this->refuse('code', 'Too many attempts.', $perIp);
        }

        $attempts = $this->attemptKey($email);

        if (RateLimiter::tooManyAttempts($attempts, self::VERIFY_ATTEMPTS)) {
            // Charged here too. Leaving this out was the whole bug: an
            // attacker working one address would exhaust its five and then
            // guess for free forever, because every later attempt returned
            // through this branch without ever touching their own budget.
            RateLimiter::hit($perIp, self::WINDOW_SECONDS);
            $this->burn($email);

            return false;
        }

        $stored = Cache::get($this->codeKey($email));

        if (is_string($stored) && hash_equals($stored, $this->hash($code))) {
            $this->burn($email);
            RateLimiter::clear($attempts);

            return true;
        }

        // Only failures are counted, on both keys. Someone who signs in on
        // the first try has spent nothing, which is what keeps an office
        // sharing one address clear of a limit aimed at a guesser.
        RateLimiter::hit($attempts, self::WINDOW_SECONDS);
        RateLimiter::hit($perIp, self::WINDOW_SECONDS);

        if (RateLimiter::tooManyAttempts($attempts, self::VERIFY_ATTEMPTS)) {
            $this->burn($email);
        }

        return false;
    }

    /**
     * One wording for every limit, so the wait is always stated rather than
     * left as a dead end the person has to guess their way out of.
     *
     * @throws ValidationException
     */
    private function refuse(string $field, string $reason, string $key): never
    {
        $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

        throw ValidationException::withMessages([
            $field => "{$reason} Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
        ]);
    }

    public function burn(string $email): void
    {
        Cache::forget($this->codeKey($email));
    }

    /** Wrong guesses against whatever code this address currently holds. */
    private function attemptKey(string $email): string
    {
        return 'otp-verify:'.$this->digest($email);
    }

    private function codeKey(string $email): string
    {
        return 'otp:'.$this->digest($email);
    }

    private function digest(string $email): string
    {
        return hash('sha256', $email);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
