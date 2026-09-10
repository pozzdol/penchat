<?php

use App\Events\ConversationTouched;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/** Channel names as they appear on the wire, for readable assertions. */
function channels(object $event): array
{
    return collect($event->broadcastOn())->map(fn ($c) => (string) $c)->sort()->values()->all();
}

it('announces a new message to the room and a signal to every participant', function () {
    Event::fake([MessageSent::class, ConversationTouched::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'hello']);

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($dm) {
        return $e->message->body === 'hello'
            && channels($e) === ["private-conversation.{$dm->id}"];
    });

    Event::assertDispatched(ConversationTouched::class, function (ConversationTouched $e) use ($me, $other) {
        return channels($e) === collect(["private-user.{$me->id}", "private-user.{$other->id}"])->sort()->values()->all();
    });
});

it('sends the wire shape the client types describe, without the per-viewer field', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'hello');

    $payload = (new MessageSent($m))->broadcastWith()['message'];

    // Mirrors the `Message` interface in resources/js/types/index.ts.
    expect(array_keys($payload))->toBe([
        'id', 'conversation_id', 'user_id', 'body',
        'created_at', 'edited_at', 'deleted_at', 'attachments',
    ]);
});

/**
 * The names the client subscribes to. Renaming an event class must not
 * silently stop every browser receiving it.
 */
it('broadcasts under names of its own, not PHP class names', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    expect((new MessageSent(say($dm, $me, 'x')))->broadcastAs())->toBe('message.sent')
        ->and((new ConversationTouched($dm))->broadcastAs())->toBe('conversation.touched');
});

it('carries nothing but an id on the thin signal', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    expect((new ConversationTouched($dm))->broadcastWith())->toBe(['conversation_id' => $dm->id]);
});

/**
 * The regression this guards is a real leak, not a style point. A channel
 * payload is identical for everyone subscribed, but visibility is per-viewer:
 * broadcasting an edit pushes the new text past the very filter that was
 * hiding it, so someone who chose "delete for me" watches the message
 * reappear the moment its author fixes a typo.
 */
it('never puts an edit or a tombstone on the wire, only the thin signal', function () {
    Event::fake([MessageSent::class, ConversationTouched::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $me, 'helo');

    $this->actingAs($me)->patch("/messages/{$m->id}", ['body' => 'hello']);
    $this->actingAs($me)->delete("/messages/{$m->id}");

    Event::assertNotDispatched(MessageSent::class);
    Event::assertDispatchedTimes(ConversationTouched::class, 2);
});

it('does push a brand new message, which no filter can be hiding yet', function () {
    Event::fake([MessageSent::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'hello']);

    Event::assertDispatchedTimes(MessageSent::class, 1);
});

/** Per-viewer, so there is nobody else to tell. */
it('stays quiet for delete-for-me and clear-history', function () {
    Event::fake([MessageSent::class, ConversationTouched::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $other, 'hide me');

    $this->actingAs($me)->delete("/messages/{$m->id}/mine");
    $this->actingAs($me)->delete("/conversations/{$dm->id}/history");

    Event::assertNotDispatched(MessageSent::class);
    Event::assertNotDispatched(ConversationTouched::class);
});

it('signals the other side only when the delete was for both', function () {
    Event::fake([ConversationTouched::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $mine = Conversation::findOrCreateDirect($me, $other);
    $this->actingAs($me)->delete("/conversations/{$mine->id}");
    Event::assertNotDispatched(ConversationTouched::class);

    [$a, $b] = User::factory()->count(2)->create();
    $both = Conversation::findOrCreateDirect($a, $b);
    $this->actingAs($a)->delete("/conversations/{$both->id}", ['also_for_other' => true]);
    Event::assertDispatched(ConversationTouched::class);
});

it('signals when the read pointer moves, and not when it does not', function () {
    Event::fake([ConversationTouched::class]);

    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $m = say($dm, $other, 'read me');

    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id]);
    Event::assertDispatchedTimes(ConversationTouched::class, 1);

    // Same pointer again: nothing changed for anyone.
    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $m->id]);
    Event::assertDispatchedTimes(ConversationTouched::class, 1);
});

it('still reaches someone who was just removed', function () {
    Event::fake([ConversationTouched::class]);

    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Team', $owner, [$member]);

    $this->actingAs($owner)->delete("/conversations/{$g->id}/members/{$member->id}");

    Event::assertDispatched(ConversationTouched::class, function (ConversationTouched $e) use ($member) {
        return in_array("private-user.{$member->id}", channels($e), true);
    });
});

/** Nothing to tell, and the conversation row is gone. */
it('says nothing when the last participant leaves', function () {
    Event::fake([ConversationTouched::class]);

    $owner = User::factory()->create();
    $g = group('Solo', $owner);

    $this->actingAs($owner)->delete("/conversations/{$g->id}/membership");

    Event::assertNotDispatched(ConversationTouched::class);
    expect(Conversation::whereKey($g->id)->exists())->toBeFalse();
});

it('tells people they were added to a group they never asked for', function () {
    Event::fake([ConversationTouched::class]);

    [$owner, $invitee] = User::factory()->count(2)->create();
    acquainted($owner, $invitee);

    $this->actingAs($owner)->post('/conversations', [
        'name' => 'Ridge Crew',
        'usernames' => [$invitee->username],
    ]);

    Event::assertDispatched(ConversationTouched::class, function (ConversationTouched $e) use ($invitee) {
        return in_array("private-user.{$invitee->id}", channels($e), true);
    });
});

/**
 * `toOthers()` is what stops the author's own optimistic bubble racing a copy
 * of itself over the socket. It only works if the client's socket id actually
 * reaches Laravel, so this asserts the plumbing rather than assuming it.
 */
it('suppresses the sender copy when the socket id arrives', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
        $sent[] = $e->socket;
    });

    $this->actingAs($me)
        ->withHeader('X-Socket-Id', '1234.5678')
        ->post("/conversations/{$dm->id}/messages", ['body' => 'hello']);

    expect($sent)->toBe(['1234.5678']);
});

it('leaves the socket id null when the client did not send one', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $sent = [];
    // A closure, not an arrow fn: `fn()` captures by value, so the append
    // would land on a copy and this would pass by never running at all.
    Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
        $sent[] = $e->socket;
    });

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'hello']);

    expect($sent)->toBe([null]);
});
