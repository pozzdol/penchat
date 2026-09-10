<?php

use App\Models\PushSubscription;
use App\Models\User;

/** The shape the browser's `PushSubscription.toJSON()` produces. */
function subscriptionPayload(string $endpoint = 'https://push.example.com/abc'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => str_repeat('k', 87), 'auth' => str_repeat('a', 22)],
    ];
}

it('stores a subscription for the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/push/subscriptions', subscriptionPayload())
        ->assertNoContent();

    $row = PushSubscription::sole();

    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint)->toBe('https://push.example.com/abc')
        ->and($row->auth_token)->toBe(str_repeat('a', 22))
        ->and($row->last_used_at)->not->toBeNull();
});

/**
 * A browser hands back the same endpoint on every load, and `use-push.ts`
 * re-posts it every time to keep the two sides in step. Without the upsert
 * that would be one new row per page view, and one push per row.
 */
it('keeps one row per device however often it is posted', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)
            ->postJson('/push/subscriptions', subscriptionPayload())
            ->assertNoContent();
    }

    expect(PushSubscription::count())->toBe(1);
});

/**
 * A shared browser signed out and back in as somebody else is the same
 * endpoint belonging to a different person. The previous owner has to stop
 * receiving on it, or one person's messages arrive on another's lock screen.
 */
it('moves a device to whoever is signed in on it now', function () {
    [$first, $second] = User::factory()->count(2)->create();

    $this->actingAs($first)->postJson('/push/subscriptions', subscriptionPayload());
    $this->actingAs($second)->postJson('/push/subscriptions', subscriptionPayload());

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->user_id)->toBe($second->id);
});

it('deletes a subscription on request', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/abc',
    ]);

    $this->actingAs($user)
        ->deleteJson('/push/subscriptions', ['endpoint' => 'https://push.example.com/abc'])
        ->assertNoContent();

    expect(PushSubscription::count())->toBe(0);
});

/**
 * An endpoint is unguessable, but "unguessable" is not an authorization rule.
 * Without the scope, knowing one would be a way to silence somebody.
 */
it('refuses to delete a device belonging to someone else', function () {
    [$owner, $other] = User::factory()->count(2)->create();
    PushSubscription::factory()->create([
        'user_id' => $owner->id,
        'endpoint' => 'https://push.example.com/abc',
    ]);

    $this->actingAs($other)
        ->deleteJson('/push/subscriptions', ['endpoint' => 'https://push.example.com/abc'])
        ->assertNoContent();

    expect(PushSubscription::count())->toBe(1);
});

it('rejects an endpoint that is not an https url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/push/subscriptions', subscriptionPayload('http://push.example.com/abc'))
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::count())->toBe(0);
});

it('rejects a subscription with no keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/push/subscriptions', ['endpoint' => 'https://push.example.com/abc'])
        ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
});

it('turns a guest away', function () {
    $this->postJson('/push/subscriptions', subscriptionPayload())->assertUnauthorized();

    expect(PushSubscription::count())->toBe(0);
});

/**
 * A suspension is read-only: it stops you putting content in front of other
 * people. A notification is content arriving *at* you, so registering the
 * device that receives it stays open.
 */
it('lets a suspended account manage its own devices', function () {
    $user = User::factory()->create(['suspended_until' => now()->addDay()]);

    $this->actingAs($user)
        ->postJson('/push/subscriptions', subscriptionPayload())
        ->assertNoContent();

    expect(PushSubscription::count())->toBe(1);
});

/** The row is the account's; nothing should outlive it. */
it('takes a device with the account it belonged to', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(PushSubscription::count())->toBe(0);
});
