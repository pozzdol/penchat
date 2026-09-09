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
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'email' => "Too many codes requested. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
                ]);
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($email), $this->hash($code), now()->addMinutes(self::TTL_MINUTES));

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
     */
    public function verify(string $email, string $code): bool
    {
        $attempts = 'otp-verify:'.$this->digest($email);

        if (RateLimiter::tooManyAttempts($attempts, self::VERIFY_ATTEMPTS)) {
            $this->burn($email);

            return false;
        }

        $stored = Cache::get($this->codeKey($email));

        if (is_string($stored) && hash_equals($stored, $this->hash($code))) {
            $this->burn($email);
            RateLimiter::clear($attempts);

            return true;
        }

        RateLimiter::hit($attempts, self::WINDOW_SECONDS);

        if (RateLimiter::tooManyAttempts($attempts, self::VERIFY_ATTEMPTS)) {
            $this->burn($email);
        }

        return false;
    }

    public function burn(string $email): void
    {
        Cache::forget($this->codeKey($email));
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
