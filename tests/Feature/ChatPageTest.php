<?php

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

function say(Conversation $c, User $from, string $body): Message
{
    return Message::factory()->create(['conversation_id' => $c->id, 'user_id' => $from->id, 'body' => $body]);
}

it('renders the chat page with the payload shape the client expects', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'hello');

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->component('chat')
        ->where('current_user.id', $me->id)
        ->where('current_user.online', true)
        ->where('active_conversation_id', $dm->id)
        ->has('conversations', 1, fn ($c) => $c
            ->where('id', $dm->id)
            ->where('type', 'direct')
            ->where('name', null)
            ->has('participants', 2)
            ->where('last_message.body', 'hello')
            ->where('unread_count', 1)
            ->where('mentioned', false))
        ->has('messages', 1, fn ($m) => $m
            ->where('body', 'hello')
            ->where('user_id', $other->id)
            ->where('attachments', [])
            ->etc()));
});

it('counts unread from the read pointer and ignores my own messages', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $read = say($dm, $other, 'read this');
    say($dm, $other, 'unread one');
    say($dm, $other, 'unread two');
    say($dm, $me, 'my own');
    $dm->participants()->updateExistingPivot($me->id, ['last_read_message_id' => $read->id]);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.unread_count', 2));
});

it('marks my messages read once every other participant has read them', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $seen = say($dm, $me, 'seen');
    say($dm, $me, 'not yet');
    $dm->participants()->updateExistingPivot($other->id, ['last_read_message_id' => $seen->id]);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('messages.0.delivery', 'read')
        ->where('messages.1.delivery', 'sent'));
});

it('orders conversations by their latest message', function () {
    [$me, $a, $b] = User::factory()->count(3)->create();
    $old = Conversation::findOrCreateDirect($me, $a);
    $new = Conversation::findOrCreateDirect($me, $b);

    Message::factory()->create(['conversation_id' => $old->id, 'user_id' => $a->id, 'created_at' => now()->subHour()]);
    Message::factory()->create(['conversation_id' => $new->id, 'user_id' => $b->id, 'created_at' => now()]);

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->where('conversations.0.id', $new->id)
        ->where('conversations.1.id', $old->id));
});

it('only lists conversations I belong to and opens the requested one', function () {
    [$me, $a, $b, $c] = User::factory()->count(4)->create();
    $mine = Conversation::findOrCreateDirect($me, $a);
    $group = Conversation::factory()->create(['type' => ConversationType::Group, 'name' => 'Ops', 'created_by' => $b->id]);
    $group->participants()->attach([$me->id, $b->id]);
    $notMine = Conversation::findOrCreateDirect($b, $c);

    $this->actingAs($me)->get("/c/{$group->id}")->assertInertia(fn ($page) => $page
        ->where('active_conversation_id', $group->id)
        ->has('conversations', 2)
        ->where('conversations.0.id', fn ($id) => in_array($id, [$mine->id, $group->id], true))
        ->missing('conversations.2'));

    expect(collect([$mine->id, $group->id]))->not->toContain($notMine->id);
});

it('renders an empty state when I have no conversations', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page
        ->where('active_conversation_id', null)
        ->where('conversations', [])
        ->where('messages', []));
});
