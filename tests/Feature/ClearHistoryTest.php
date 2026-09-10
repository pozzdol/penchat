<?php

use App\Models\Conversation;
use App\Models\User;

/**
 * Clearing and deleting both move the same pointers. What separates them is
 * whether the row survives on the list, so most of these tests are about the
 * list rather than about the messages.
 */
it('empties the thread for me and leaves it full for the other side', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'before');

    $this->actingAs($me)->delete("/conversations/{$dm->id}/history")->assertRedirect();

    $this->actingAs($me)->get("/c/{$dm->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 0));

    $this->actingAs($other)->get("/c/{$dm->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 1));
});

it('keeps the row on the list, with no preview left to show', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'before');

    $this->actingAs($me)->delete("/conversations/{$dm->id}/history");

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->has('conversations', 1, fn ($c) => $c
            ->where('last_message', null)
            ->where('unread_count', 0)
            ->etc()));
});

/**
 * The bug this guards: moving only the cleared pointer leaves a badge counting
 * messages the viewer can no longer reach, and nothing they can do clears it.
 */
it('takes the unread badge with it', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'one');
    say($dm, $other, 'two');

    $this->actingAs($me)->delete("/conversations/{$dm->id}/history");

    $pivot = $dm->participants()->whereKey($me->id)->first()->pivot;
    expect($pivot->cleared_up_to_message_id)->not->toBeNull()
        ->and($pivot->last_read_message_id)->toBe($pivot->cleared_up_to_message_id);
});

it('shows messages sent after the clear', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'before');

    $this->actingAs($me)->delete("/conversations/{$dm->id}/history");
    say($dm, $other, 'after');

    $this->actingAs($me)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->has('messages', 1, fn ($m) => $m->where('body', 'after')->etc()));
});

it('lets a group member clear without touching anyone else', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Team', $owner, [$member]);
    say($g, $owner, 'kickoff');

    $this->actingAs($member)->delete("/conversations/{$g->id}/history")->assertRedirect();

    $this->actingAs($member)->get("/c/{$g->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 0));
    $this->actingAs($owner)->get("/c/{$g->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 1));
});

it('refuses a non-participant', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($stranger)->delete("/conversations/{$dm->id}/history")->assertForbidden();
});
