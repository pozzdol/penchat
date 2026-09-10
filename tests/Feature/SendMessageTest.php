<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('persists a message and moves the sender read pointer to it', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => '  hello  '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $message = Message::sole();

    expect($message->body)->toBe('hello')
        ->and($message->conversation_id)->toBe($dm->id)
        ->and($message->user_id)->toBe($me->id)
        ->and($dm->participants()->whereKey($me->id)->first()->pivot->last_read_message_id)
        ->toBe($message->id);
});

/**
 * The reason ChatController hands Inertia closures instead of arrays: a prop
 * left out of `only` must never be computed. This fails the moment someone
 * reverts them to eager values.
 *
 * Asserted against the raw JSON because assertInertia() reads `viewData('page')`,
 * which only exists on a full HTML response — a partial reload has no view.
 */
it('returns only the props the client asked for', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'hello');

    // The asset version is only settled inside a request, by the middleware.
    // Reading it from a real response is what keeps this from silently turning
    // into a 409-redirect test that passes for the wrong reason.
    $version = $this->actingAs($me)->get('/')->viewData('page')['version'];

    $response = $this->actingAs($me)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'chat',
            'X-Inertia-Partial-Data' => 'messages,conversations',
        ])
        ->get("/c/{$dm->id}");

    $response->assertOk();

    $props = $response->json('props');

    expect(array_keys($props))
        // `errors` is shared as an always-prop, so validation still reaches the
        // client on a partial reload. Everything else was skipped.
        ->toEqualCanonicalizing(['messages', 'conversations', 'errors'])
        ->and($props['messages'])->toHaveCount(1)
        ->and($props['conversations'])->toHaveCount(1);
});

it('rejects an empty message', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => '   '])
        ->assertSessionHasErrors('body');

    expect(Message::count())->toBe(0);
});

it('rejects a message longer than the limit', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => str_repeat('a', 4001)])
        ->assertSessionHasErrors('body');
});

it('refuses a non-participant', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($a, $b);

    $this->actingAs($outsider)->post("/conversations/{$dm->id}/messages", ['body' => 'hi'])
        ->assertForbidden();

    expect(Message::count())->toBe(0);
});

it('refuses a guest', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($a, $b);

    $this->post("/conversations/{$dm->id}/messages", ['body' => 'hi'])->assertRedirect('/login');

    expect(Message::count())->toBe(0);
});

it('puts the conversation at the top of the sender list', function () {
    [$me, $a, $b] = User::factory()->count(3)->create();
    $older = Conversation::findOrCreateDirect($me, $a);
    $newer = Conversation::findOrCreateDirect($me, $b);

    Message::factory()->create(['conversation_id' => $older->id, 'user_id' => $a->id, 'created_at' => now()->subHour()]);
    Message::factory()->create(['conversation_id' => $newer->id, 'user_id' => $b->id, 'created_at' => now()]);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.id', $newer->id));

    $this->actingAs($me)->post("/conversations/{$older->id}/messages", ['body' => 'up we go']);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.id', $older->id)
        ->where('conversations.0.last_message.body', 'up we go'));
});
