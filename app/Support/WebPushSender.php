<?php

namespace App\Support;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * How the bytes reach a device. Nothing in here knows what a message is or
 * who should hear about one — that is {@see PushNotifier}'s job, and the split
 * is the point: a native app later brings a different transport (APNs, FCM)
 * with different credentials and a different token lifecycle, but the question
 * of *who to tell and what it says* does not change and must not be rewritten
 * alongside it.
 *
 * Resolved from the container rather than called statically, so a test can
 * bind a spy in its place. That is the only reason this is not another
 * `SpamGuard`-shaped static.
 */
class WebPushSender
{
    /** A push service that hangs must not hold a PHP worker open. */
    private const TIMEOUT_SECONDS = 5;

    /**
     * The HTTP client is injectable so a test can drive a push service that
     * answers 410 without reaching the network — which is the only way to
     * exercise the pruning below, and pruning is what keeps this table from
     * filling with devices that no longer exist.
     */
    public function __construct(private ?ClientInterface $client = null) {}

    /**
     * Deliver one payload to many devices, and forget the devices that are
     * gone.
     *
     * Never throws. A push that fails is a notification nobody sees; a push
     * that throws would, from `MessageSent`'s terminating callback, be an
     * unhandled error on a request whose response has already been sent. The
     * message itself is safely committed either way, so the only honest
     * outcome here is a log line.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    public function send(Collection $subscriptions, array $payload): void
    {
        if ($subscriptions->isEmpty() || ! $this->configured()) {
            return;
        }

        try {
            $push = new WebPush(
                ['VAPID' => [
                    'subject' => (string) config('services.webpush.subject'),
                    'publicKey' => (string) config('services.webpush.public_key'),
                    'privateKey' => (string) config('services.webpush.private_key'),
                ]],
                // A notification nobody collected within the hour is stale —
                // by then they have opened the app and read it anyway.
                ['TTL' => 3600, 'urgency' => 'high'],
                $this->client ?? new Client(['timeout' => self::TIMEOUT_SECONDS]),
            );

            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $subscription) {
                $push->queueNotification($this->describe($subscription), $body);
            }

            $gone = [];

            foreach ($push->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $gone[] = $report->getEndpoint();

                    continue;
                }

                if (! $report->isSuccess()) {
                    Log::warning('Web push rejected.', [
                        'endpoint' => $report->getEndpoint(),
                        'reason' => $report->getReason(),
                    ]);
                }
            }

            $this->forget($gone);
        } catch (\Throwable $e) {
            Log::error('Web push failed.', ['exception' => $e]);
        }
    }

    /** Both halves of the key pair, or there is nothing to sign a push with. */
    private function configured(): bool
    {
        return filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'));
    }

    /**
     * `aes128gcm` explicitly. The library still defaults to the legacy
     * `aesgcm` for backwards compatibility, and browsers have been reporting
     * the RFC 8291 encoding since 2017 — leaving it to the default would send
     * every payload in a scheme nothing asked for.
     */
    private function describe(PushSubscription $subscription): Subscription
    {
        return new Subscription(
            $subscription->endpoint,
            $subscription->public_key,
            $subscription->auth_token,
            'aes128gcm',
        );
    }

    /**
     * 404 or 410 from a push service means the browser threw the subscription
     * away — cleared site data, uninstalled the PWA, revoked permission. The
     * row is dead and nothing will ever revive it, so deleting here is what
     * keeps the table from filling with devices that no longer exist.
     *
     * @param  list<string>  $endpoints
     */
    private function forget(array $endpoints): void
    {
        if ($endpoints === []) {
            return;
        }

        PushSubscription::whereIn('endpoint', $endpoints)->delete();
    }
}
