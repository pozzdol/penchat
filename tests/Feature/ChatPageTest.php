<?php

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('renders the chat page with the payload shape the client expects', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $other, 'hello');

    $this->actingAs($me)->get('/')->assertInertia(fn ($page) => $page
        ->component('chat')
        ->where('current_user.id', $me->id)
        ->where('current_user.username', $me->username)
        ->where('current_user.online', true)
        ->where('active_conversation_id', $dm->id)
        ->has('conversations', 1, fn ($c) => $c
            ->where('id', $dm->id)
            ->where('type', 'direct')
            ->where('name', null)
            // A direct chat has no owner and no roles: two equals.
            ->where('owner_id', null)
            ->where('viewer_role', 'member')
            ->where('members_can_add', false)
            ->where('admins_can_promote', false)
            ->has('participants', 2, fn ($p) => $p
                ->where('username', fn ($u) => is_string($u))
                ->where('role', null)
                ->etc())
            ->where('last_message.body', 'hello')
            ->where('unread_count', 1)
            ->where('mentioned', false)
            // Every management ability is refused outside a group.
            ->has('can', fn ($can) => $can
                ->where('add_member', false)
                ->where('remove_member', false)
                ->where('manage_admins', false)
                ->where('update_settings', false)
                ->where('update_owner_settings', false)
                ->where('transfer_ownership', false)
                ->where('leave', false)
                // Deleting the chat is the one thing a direct chat allows and
                // a group does not.
                ->where('delete_chat', true)
                ->where('delete_any_message', false)))
        ->has('messages', 1, fn ($m) => $m
            ->where('body', 'hello')
            ->where('user_id', $other->id)
            ->where('attachments', [])
            ->where('edited_at', null)
            ->where('deleted_at', null)
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
    $group = Conversation::factory()->create(['type' => ConversationType::Group, 'name' => 'Ops', 'owner_id' => $b->id]);
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
