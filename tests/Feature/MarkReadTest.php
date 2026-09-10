<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Str;

function pointer(Conversation $c, User $u): ?string
{
    return $c->participants()->whereKey($u->id)->first()->pivot->last_read_message_id;
}

it('advances the read pointer and clears the badge', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'one');
    $last = say($dm, $other, 'two');

    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $last->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(pointer($dm, $me))->toBe($last->id);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.unread_count', 0));
});

it('never rewinds the pointer', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $first = say($dm, $other, 'one');
    $second = say($dm, $other, 'two');

    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $second->id]);
    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $first->id]);

    expect(pointer($dm, $me))->toBe($second->id);
});

it('clamps an id from another conversation instead of accepting it', function () {
    [$me, $a, $b] = User::factory()->count(3)->create();
    $mine = Conversation::findOrCreateDirect($me, $a);
    $elsewhere = Conversation::findOrCreateDirect($me, $b);

    $here = say($mine, $a, 'here');
    $there = say($elsewhere, $b, 'there');

    // A far-future id from a different conversation must not drag this pointer
    // past what this conversation actually contains.
    $this->actingAs($me)->patch("/conversations/{$mine->id}/read", ['message_id' => $there->id]);

    expect(pointer($mine, $me))->toBe($here->id);
});

it('does nothing when there is nothing to read', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    // A well-formed ULID that belongs to no message: the pointer must not move.
    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => (string) Str::ulid()])
        ->assertRedirect();

    expect(pointer($dm, $me))->toBeNull();
});

it('refuses a non-participant', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($a, $b);
    $m = say($dm, $a, 'hi');

    $this->actingAs($outsider)->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id])
        ->assertForbidden();
});

// Separate test on purpose: actingAs persists for the rest of a test, so a
// guest assertion after an authenticated one is not testing a guest at all.
it('refuses a guest', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($a, $b);
    $m = say($dm, $a, 'hi');

    $this->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id])
        ->assertRedirect('/login');
});

/**
 * The property the whole key choice turns on. Read state is a high-water mark
 * compared with `>` and `<=`, so it only works while ids sort in the order the
 * messages were written. UUIDv4 would fail this; ULID passes it.
 *
 * If this ever breaks, the pointer design is broken — not this test.
 */
it('keeps message ids sorting in the order they were written', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $ids = collect(range(1, 25))->map(fn ($n) => say($dm, $other, "message {$n}")->id);

    expect($ids->all())->toBe($ids->sort()->values()->all())
        ->and($ids->unique())->toHaveCount(25);
});

it('leaves later messages unread when the pointer stops in the middle', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $first = say($dm, $other, 'one');
    $second = say($dm, $other, 'two');
    say($dm, $other, 'three');

    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $second->id]);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.unread_count', 1));

    expect(pointer($dm, $me))->toBe($second->id)
        ->and(strcmp($first->id, $second->id))->toBeLessThan(0);
});
