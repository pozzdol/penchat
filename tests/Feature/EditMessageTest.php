<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('lets the author fix a typo and marks the message edited', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'helo');

    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'hello'])->assertRedirect();

    $m->refresh();
    expect($m->body)->toBe('hello')->and($m->edited_at)->not->toBeNull();
});

/**
 * The window is anchored on `created_at`, never on `edited_at`. Anchoring it
 * on the last edit would let an author chain edits and keep a message open
 * forever.
 */
it('closes the window two hours after the message was written', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'helo');

    $this->travel(Message::EDIT_WINDOW_MINUTES + 1)->minutes();

    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'hello'])->assertForbidden();
    expect($m->refresh()->body)->toBe('helo');
});

it('does not let an edit reset the window', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'one');

    $this->travel(Message::EDIT_WINDOW_MINUTES - 5)->minutes();
    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'two'])->assertRedirect();

    $this->travel(10)->minutes();
    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'three'])->assertForbidden();
    expect($m->refresh()->body)->toBe('two');
});

it('refuses everyone but the author, admins included', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Team', $owner, [$member]);
    $m = say($g, $member, 'mine');

    $this->actingAs($owner)->patch("/messages/{$m->id}", ['body' => 'not yours'])->assertForbidden();
});

it('refuses a non-participant', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'mine');

    $this->actingAs($stranger)->patch("/messages/{$m->id}", ['body' => 'hijacked'])->assertForbidden();
});

it('refuses to edit a message that has been deleted', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'oops');
    $this->actingAs($me)->delete("/messages/{$m->id}");

    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'undelete me'])->assertForbidden();
});

/** Emptying a message is Delete's job, and Delete leaves a tombstone. */
it('rejects an empty body', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'something');

    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => '   '])
        ->assertSessionHasErrors('body');
});
