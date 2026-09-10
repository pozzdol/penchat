<?php

use App\Models\Conversation;
use App\Models\User;

it('takes the chat off my list and leaves theirs alone', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'hello');

    $this->actingAs($me)->delete("/conversations/{$dm->id}")->assertRedirect('/');

    $this->actingAs($me)->get('/')
        ->assertInertia(fn ($page) => $page->has('conversations', 0));
    $this->actingAs($other)->get('/')
        ->assertInertia(fn ($page) => $page->has('conversations', 1));
});

it('takes it off both lists when I asked it to', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'hello');

    $this->actingAs($me)->delete("/conversations/{$dm->id}", ['also_for_other' => true]);

    $this->actingAs($other)->get('/')
        ->assertInertia(fn ($page) => $page->has('conversations', 0));
});

/**
 * Nothing is erased, so the conversation comes back the moment it has
 * something to say — and it comes back empty, not with the history that was
 * dismissed.
 */
it('comes back when someone writes again, carrying only what is new', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'old news');

    $this->actingAs($me)->delete("/conversations/{$dm->id}");
    say($dm, $other, 'still there?');

    // The row is back on the list, and opening it shows only what is new.
    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->has('conversations', 1, fn ($c) => $c
            ->where('last_message.body', 'still there?')
            ->where('unread_count', 1)
            ->etc()));

    $this->actingAs($me)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->has('messages', 1, fn ($m) => $m->where('body', 'still there?')->etc()));
});

it('refuses on a group, which is left rather than deleted', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Team', $owner, [$member]);

    $this->actingAs($member)->delete("/conversations/{$g->id}")->assertForbidden();
});

it('refuses a non-participant', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($stranger)->delete("/conversations/{$dm->id}")->assertForbidden();
});

/**
 * Deleting is not leaving: the pivot row survives, which is what lets the
 * conversation reappear rather than having to be created again.
 */
it('keeps me in the conversation', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->delete("/conversations/{$dm->id}");

    expect($dm->participants()->whereKey($me->id)->exists())->toBeTrue();
});
