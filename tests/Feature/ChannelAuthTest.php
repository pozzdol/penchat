<?php

use App\Models\Conversation;
use App\Models\User;

/**
 * `routes/channels.php` is the only thing standing between users and each
 * other's chats, so these go through the real `/broadcasting/auth` route
 * rather than calling the callbacks directly — the route, its middleware and
 * the callback all have to agree.
 *
 * The driver has to be switched away from the test default first. `phpunit.xml`
 * sets `BROADCAST_CONNECTION=null`, and `NullBroadcaster::auth()` is an empty
 * method: it never opens `routes/channels.php` and answers 200 to everyone.
 * Written the obvious way, every test below passes whatever the callbacks say —
 * including if they say nothing at all. Signing is a local HMAC, so dummy
 * credentials are enough and nothing leaves the machine.
 */
beforeEach(function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);

    // `Broadcast::channel()` forwards to whichever driver is default *at the
    // moment it runs* (BroadcastManager::__call → driver()->channel()). The
    // channels file ran at boot, against the null driver, so the reverb driver
    // resolved above starts with none — and a broadcaster with no channels
    // refuses everyone, which looks exactly like a working deny rule.
    require base_path('routes/channels.php');
});

function auth_channel(string $channel): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => $channel];
}

it('lets a participant subscribe to their conversation', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)
        ->post('/broadcasting/auth', auth_channel("private-conversation.{$dm->id}"))
        ->assertOk();
});

it('refuses a stranger', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($stranger)
        ->post('/broadcasting/auth', auth_channel("private-conversation.{$dm->id}"))
        ->assertForbidden();
});

it('refuses someone who left the group', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Team', $owner, [$member]);

    $this->actingAs($member)
        ->post('/broadcasting/auth', auth_channel("private-conversation.{$g->id}"))
        ->assertOk();

    $g->removeParticipant($member);

    $this->actingAs($member)
        ->post('/broadcasting/auth', auth_channel("private-conversation.{$g->id}"))
        ->assertForbidden();
});

it('refuses a conversation that does not exist', function () {
    $me = User::factory()->create();

    $this->actingAs($me)
        ->post('/broadcasting/auth', auth_channel('private-conversation.01ARZ3NDEKTSV4RRFFQ69G5FAV'))
        ->assertForbidden();
});

it('lets someone subscribe to their own line and nobody else to it', function () {
    [$me, $other] = User::factory()->count(2)->create();

    $this->actingAs($me)
        ->post('/broadcasting/auth', auth_channel("private-user.{$me->id}"))
        ->assertOk();

    $this->actingAs($other)
        ->post('/broadcasting/auth', auth_channel("private-user.{$me->id}"))
        ->assertForbidden();
});

/**
 * The bug the scaffolding shipped with. `(int) $user->id === (int) $id` is
 * correct for auto-increment keys; on a ULID both sides cast to 1, so it
 * authorized everyone for everyone. This asserts the comparison is textual.
 */
it('does not let one ULID pass for another', function () {
    [$me, $other] = User::factory()->count(2)->create();

    expect((int) $me->id)->toBe((int) $other->id)
        ->and($me->id)->not->toBe($other->id);

    $this->actingAs($other)
        ->post('/broadcasting/auth', auth_channel("private-user.{$me->id}"))
        ->assertForbidden();
});

it('puts a signed-in user on the presence roster with a thin payload', function () {
    $me = User::factory()->create();

    $response = $this->actingAs($me)
        ->post('/broadcasting/auth', auth_channel('presence-online'))
        ->assertOk();

    $info = json_decode(json_decode($response->getContent(), true)['channel_data'], true);

    expect($info['user_info'])->toBe(['id' => $me->id, 'name' => $me->name])
        ->and($info['user_info'])->not->toHaveKey('email');
});

/**
 * 403 rather than a redirect to the sign-in page: the route carries `web` but
 * not `auth`, and the broadcaster is what turns a guest away. That is the right
 * answer here — Echo speaks XHR and would have nothing to do with a redirect.
 */
it('refuses a guest on every channel', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    foreach (["private-conversation.{$dm->id}", "private-user.{$me->id}", 'presence-online'] as $channel) {
        $this->post('/broadcasting/auth', auth_channel($channel))->assertForbidden();
    }
});
