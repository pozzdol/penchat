<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PushSubscription> */
class PushSubscriptionFactory extends Factory
{
    /**
     * A real P-256 public key, generated once per process and shared.
     *
     * Random bytes will not do. The payload is encrypted against this key
     * before any HTTP call happens, so a fake one fails inside the library
     * long before a test gets to assert anything about pushing.
     *
     * @var array{p256dh: string, auth: string}|null
     */
    private static ?array $keys = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $keys = self::keys();

        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://push.example.com/'.fake()->uuid(),
            'public_key' => $keys['p256dh'],
            'auth_token' => $keys['auth'],
            'user_agent' => 'Mozilla/5.0 (Test)',
            'last_used_at' => now(),
        ];
    }

    /**
     * The uncompressed point form the Push API hands out: a 0x04 byte, then
     * the two 32-byte coordinates, base64url with the padding stripped.
     *
     * @return array{p256dh: string, auth: string}
     */
    private static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $details = openssl_pkey_get_details($key);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return self::$keys = [
            'p256dh' => self::base64url("\x04".$x.$y),
            'auth' => self::base64url(random_bytes(16)),
        ];
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
