<?php

use App\Events\ConversationTouched;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** The tick the sender sees on their own last message. */
function tickFor(User $sender, Conversation $conversation): string
{
    $page = test()->actingAs($sender)->get("/c/{$conversation->id}");

    return collect($page->viewData('page')['props']['messages'])->last()['delivery'];
}

function pivotOf(Conversation $conversation, User $user): object
{
    return DB::table('conversation_user')
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->first();
}

it('walks sent, then delivered, then read', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'hello');

    expect(tickFor($me, $dm))->toBe('sent');

    // Their device has it, but they have not opened the conversation.
    $this->actingAs($other)->post('/delivered')->assertRedirect();
    expect(tickFor($me, $dm))->toBe('delivered');

    $this->actingAs($other)->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id]);
    expect(tickFor($me, $dm))->toBe('read');
});

/**
 * The loop this feature could easily have created: acking moves a pointer,
 * moving a pointer tells everyone, and everyone acks when told. It settles
 * only because a pointer that cannot move announces nothing.
 */
it('goes quiet after one round instead of acking forever', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    // Through the real endpoint: sending is what sets the author's own
    // pointers, and a test that skips it leaves a pointer free to move later
    // and hides the very thing this is measuring.
    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'hello']);

    Event::fake([ConversationTouched::class]);

    // Each client acks on hearing the other's signal. If a settled pointer
    // still announced itself, this would never stop.
    foreach (range(1, 4) as $round) {
        $this->actingAs($other)->post('/delivered');
        $this->actingAs($me)->post('/delivered');
    }

    Event::assertDispatchedTimes(ConversationTouched::class, 1);
});

it('never rewinds a pointer', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $me, 'first');

    $this->actingAs($other)->post('/delivered');
    $ahead = pivotOf($dm, $other)->last_delivered_message_id;

    // A second ack with nothing new must leave it exactly where it was.
    $this->actingAs($other)->post('/delivered');

    expect(pivotOf($dm, $other)->last_delivered_message_id)->toBe($ahead);
});

/**
 * Reading implies receiving. If the two pointers could disagree, a message
 * would report `read` while the tick logic still called it delivered.
 */
it('keeps delivered from lagging behind read', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'hello');

    // Marked read without ever acking delivery — the offline-then-open case.
    $this->actingAs($other)->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id]);

    $pivot = pivotOf($dm, $other);
    expect(strcmp(
        (string) $pivot->last_delivered_message_id,
        (string) $pivot->last_read_message_id,
    ))->toBeGreaterThanOrEqual(0);

    expect(tickFor($me, $dm))->toBe('read');
});

it('holds the group back to whoever is furthest behind', function () {
    [$owner, $b, $c] = User::factory()->count(3)->create();
    $g = group('Ridge Crew', $owner, [$b, $c]);
    $m = say($g, $owner, 'anyone about?');

    expect(tickFor($owner, $g))->toBe('sent');

    $this->actingAs($b)->post('/delivered');
    expect(tickFor($owner, $g))->toBe('sent');

    $this->actingAs($c)->post('/delivered');
    expect(tickFor($owner, $g))->toBe('delivered');

    $this->actingAs($b)->patch("/conversations/{$g->id}/read", ['message_id' => $m->id]);
    expect(tickFor($owner, $g))->toBe('delivered');

    $this->actingAs($c)->patch("/conversations/{$g->id}/read", ['message_id' => $m->id]);
    expect(tickFor($owner, $g))->toBe('read');
});

it('acks every conversation at once, including the ones not open', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $one = Conversation::findOrCreateDirect($me, $other);
    $two = group('Ridge Crew', $me, [$other]);

    say($one, $me, 'first');
    say($two, $me, 'second');

    $this->actingAs($other)->post('/delivered');

    expect(tickFor($me, $one))->toBe('delivered')
        ->and(tickFor($me, $two))->toBe('delivered');
});

/** A conversation the caller is not in must not be touched by a bulk ack. */
it('only ever moves the caller own pointers', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $me, 'private');

    $this->actingAs($stranger)->post('/delivered')->assertRedirect();

    expect(pivotOf($dm, $other)->last_delivered_message_id)->toBeNull()
        ->and(tickFor($me, $dm))->toBe('sent');
});

it('starts a new group member level rather than owing them a backlog', function () {
    [$owner, $joiner] = User::factory()->count(2)->create();
    $g = group('Ridge Crew', $owner);
    say($g, $owner, 'before you arrived');

    $g->attachParticipants([$joiner]);

    $pivot = pivotOf($g, $joiner);
    expect($pivot->last_delivered_message_id)
        ->toBe($pivot->last_read_message_id)
        ->not->toBeNull();
});

it('refuses a guest', function () {
    $this->post('/delivered')->assertRedirect('/login');
});
