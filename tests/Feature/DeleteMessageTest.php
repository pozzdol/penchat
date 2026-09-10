<?php

use App\Models\Conversation;
use App\Models\User;

/**
 * Delete for everyone leaves a tombstone rather than removing the row. A
 * message vanishing mid-conversation reads as a bug, so the thread keeps the
 * gap visible — and the text does not survive behind it.
 */
it('tombstones the message for both sides and drops the text', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'sent in error');

    $this->actingAs($me)->delete("/messages/{$m->id}")->assertRedirect();

    $m->refresh();
    expect($m->deleted_at)->not->toBeNull()->and($m->body)->toBeNull();

    $this->actingAs($other)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->has('messages', 1, fn ($msg) => $msg
            ->where('body', null)
            ->where('deleted_at', fn ($v) => $v !== null)
            ->etc()));
});

it('lets a group admin delete anyone, and a plain member nobody but themselves', function () {
    [$owner, $member, $bystander] = User::factory()->count(3)->create();
    $g = group('Team', $owner, [$member, $bystander]);

    $theirs = say($g, $member, 'oops');
    $this->actingAs($owner)->delete("/messages/{$theirs->id}")->assertRedirect();

    $another = say($g, $member, 'still here');
    $this->actingAs($bystander)->delete("/messages/{$another->id}")->assertForbidden();
});

/** A direct chat has no admin, so only the author may. */
it('refuses the other side of a direct chat', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'mine');

    $this->actingAs($other)->delete("/messages/{$m->id}")->assertForbidden();
});

it('refuses a non-participant', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'mine');

    $this->actingAs($stranger)->delete("/messages/{$m->id}")->assertForbidden();
});

it('hides a message for me alone', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'keep');
    $hide = say($dm, $other, 'hide');

    $this->actingAs($me)->delete("/messages/{$hide->id}/mine")->assertRedirect();

    $this->actingAs($me)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->has('messages', 1, fn ($m) => $m->where('body', 'keep')->etc()));
    $this->actingAs($other)->get("/c/{$dm->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 2));
});

/** Two clicks, or a retry, land on the same state rather than a 500. */
it('is idempotent', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $other, 'hide');

    $this->actingAs($me)->delete("/messages/{$m->id}/mine")->assertRedirect();
    $this->actingAs($me)->delete("/messages/{$m->id}/mine")->assertRedirect();

    expect(DB::table('message_user_deletions')->count())->toBe(1);
});

/**
 * The sidebar preview is per-viewer now. Hiding the newest message has to fall
 * back to the one before it, for that viewer only.
 */
it('falls back to the previous message in my sidebar preview', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'older');
    $newest = say($dm, $other, 'newest');

    $this->actingAs($me)->delete("/messages/{$newest->id}/mine");

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->has('conversations', 1, fn ($c) => $c->where('last_message.body', 'older')->etc()));
    $this->actingAs($other)->get('/')->assertInertia(fn ($page) => $page
        ->has('conversations', 1, fn ($c) => $c->where('last_message.body', 'newest')->etc()));
});

it('stops counting a message I hid, and one that was deleted, as unread', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'one');
    $hidden = say($dm, $other, 'two');
    $gone = say($dm, $other, 'three');

    $this->actingAs($me)->delete("/messages/{$hidden->id}/mine");
    $this->actingAs($other)->delete("/messages/{$gone->id}");

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->has('conversations', 1, fn ($c) => $c->where('unread_count', 1)->etc()));
});
