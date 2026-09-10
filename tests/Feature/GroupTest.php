<?php

use App\Enums\ConversationRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

function roleOf(Conversation $c, User $u): ?string
{
    return $c->participants()->whereKey($u->id)->first()?->pivot->role;
}

it('creates a group with the creator as owner and admin', function () {
    $me = User::factory()->create(['username' => 'fikri']);
    $a = User::factory()->create(['username' => 'luis']);
    $b = User::factory()->create(['username' => 'paul']);
    acquainted($me, $a);
    acquainted($me, $b);

    $this->actingAs($me)->post('/conversations', [
        'name' => '  Ridgeline Deploys  ',
        'usernames' => ['@LUIS', 'paul'],
    ])->assertSessionHasNoErrors();

    $group = Conversation::where('type', 'group')->sole();

    expect($group->name)->toBe('Ridgeline Deploys')
        ->and($group->owner_id)->toBe($me->id)
        ->and($group->participants)->toHaveCount(3)
        ->and(roleOf($group, $me))->toBe('admin')
        ->and(roleOf($group, $a))->toBe('member')
        ->and(roleOf($group, $b))->toBe('member');
});

it('creates a group of one', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->post('/conversations', ['name' => 'Notes to self'])
        ->assertSessionHasNoErrors();

    expect(Conversation::sole()->participants)->toHaveCount(1);
});

it('refuses a group naming someone who does not exist', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->post('/conversations', ['name' => 'Ops', 'usernames' => ['ghost']])
        ->assertSessionHasErrors('usernames');

    expect(Conversation::count())->toBe(0);
});

it('requires a name', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->post('/conversations', ['name' => '  '])->assertSessionHasErrors('name');
});

/*
|--------------------------------------------------------------------------
| Adding members
|--------------------------------------------------------------------------
*/

it('lets an admin add someone', function () {
    [$owner, $newcomer] = User::factory()->count(2)->create();
    $g = group('Ops', $owner);
    acquainted($owner, $newcomer);

    $this->actingAs($owner)->post("/conversations/{$g->id}/members", ['usernames' => [$newcomer->username]])
        ->assertSessionHasNoErrors();

    expect($g->fresh()->participants)->toHaveCount(2);
});

it('refuses a plain member adding someone until the switch is on', function () {
    [$owner, $member, $newcomer] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$member]);
    acquainted($member, $newcomer);

    $this->actingAs($member)->post("/conversations/{$g->id}/members", ['usernames' => [$newcomer->username]])
        ->assertForbidden();

    $g->forceFill(['members_can_add' => true])->save();

    $this->actingAs($member)->post("/conversations/{$g->id}/members", ['usernames' => [$newcomer->username]])
        ->assertSessionHasNoErrors();

    expect($g->fresh()->participants)->toHaveCount(3);
});

it('refuses adding someone already in the group', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$member]);

    $this->actingAs($owner)->post("/conversations/{$g->id}/members", ['usernames' => [$member->username]])
        ->assertSessionHasErrors('usernames');

    expect($g->fresh()->participants)->toHaveCount(2);
});

/**
 * The point of setting both pointers on join: a newcomer must not open the
 * group to a wall of history they were never part of, marked unread.
 */
it('starts a new member from the present, not the backlog', function () {
    [$owner, $newcomer] = User::factory()->count(2)->create();
    $g = group('Ops', $owner);
    acquainted($owner, $newcomer);
    say($g, $owner, 'old one');
    $last = say($g, $owner, 'old two');

    $this->actingAs($owner)->post("/conversations/{$g->id}/members", ['usernames' => [$newcomer->username]]);

    $pivot = $g->fresh()->participants()->whereKey($newcomer->id)->first()->pivot;

    expect($pivot->last_read_message_id)->toBe($last->id)
        ->and($pivot->cleared_up_to_message_id)->toBe($last->id);
});

/*
|--------------------------------------------------------------------------
| Roles
|--------------------------------------------------------------------------
*/

it('lets the owner promote and demote', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$member]);

    $this->actingAs($owner)->patch("/conversations/{$g->id}/members/{$member->id}", ['role' => 'admin'])
        ->assertSessionHasNoErrors();
    expect(roleOf($g->fresh(), $member))->toBe('admin');

    $this->actingAs($owner)->patch("/conversations/{$g->id}/members/{$member->id}", ['role' => 'member']);
    expect(roleOf($g->fresh(), $member))->toBe('member');
});

it('refuses an admin appointing admins until the owner delegates it', function () {
    [$owner, $admin, $member] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$admin, $member]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($admin)->patch("/conversations/{$g->id}/members/{$member->id}", ['role' => 'admin'])
        ->assertForbidden();

    $g->forceFill(['admins_can_promote' => true])->save();

    $this->actingAs($admin)->patch("/conversations/{$g->id}/members/{$member->id}", ['role' => 'admin'])
        ->assertSessionHasNoErrors();
});

it('never lets the owner be demoted', function () {
    [$owner, $admin] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$admin]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);
    $g->forceFill(['admins_can_promote' => true])->save();

    $this->actingAs($admin)->patch("/conversations/{$g->id}/members/{$owner->id}", ['role' => 'member'])
        ->assertSessionHasErrors('role');

    $this->actingAs($owner)->patch("/conversations/{$g->id}/members/{$owner->id}", ['role' => 'member'])
        ->assertSessionHasErrors('role');

    expect(roleOf($g->fresh(), $owner))->toBe('admin');
});

it('refuses a plain member touching roles', function () {
    [$owner, $member, $other] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$member, $other]);

    $this->actingAs($member)->patch("/conversations/{$g->id}/members/{$other->id}", ['role' => 'admin'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Removing and leaving
|--------------------------------------------------------------------------
*/

it('lets an admin remove a member but never the owner', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$member]);

    $this->actingAs($owner)->delete("/conversations/{$g->id}/members/{$member->id}")
        ->assertSessionHasNoErrors();
    expect($g->fresh()->participants)->toHaveCount(1);

    $g2 = group('Ops2', $owner, [$member]);
    $g2->participants()->updateExistingPivot($member->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($member)->delete("/conversations/{$g2->id}/members/{$owner->id}")
        ->assertSessionHasErrors('member');
    expect($g2->fresh()->participants)->toHaveCount(2);
});

it('sends you to Exit group instead of removing yourself', function () {
    [$owner, $admin] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$admin]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($admin)->delete("/conversations/{$g->id}/members/{$admin->id}")
        ->assertSessionHasErrors('member');

    expect($g->fresh()->participants)->toHaveCount(2);
});

it('hands the group to the longest-standing admin when the owner leaves', function () {
    [$owner, $admin, $member] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$member, $admin]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($owner)->delete("/conversations/{$g->id}/membership")
        ->assertRedirect('/');

    $g = $g->fresh();

    expect($g->owner_id)->toBe($admin->id)
        ->and($g->participants)->toHaveCount(2);
});

/**
 * Everyone attached in one request shares a second-precision joined_at, so the
 * user_id tiebreak is what actually decides — and it has to be deterministic.
 */
it('promotes the longest-standing member when no admin remains', function () {
    [$owner, $first, $second] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$first, $second]);

    $this->actingAs($owner)->delete("/conversations/{$g->id}/membership");

    $g = $g->fresh();

    expect($g->owner_id)->toBe($first->id)
        ->and(roleOf($g, $first))->toBe('admin');
});

it('deletes the group when the last participant leaves', function () {
    $owner = User::factory()->create();
    $g = group('Ops', $owner);
    say($g, $owner, 'alone in here');

    $this->actingAs($owner)->delete("/conversations/{$g->id}/membership");

    expect(Conversation::find($g->id))->toBeNull()
        ->and(Message::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Ownership and settings
|--------------------------------------------------------------------------
*/

it('transfers ownership and makes the new owner an admin', function () {
    [$owner, $member] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$member]);

    $this->actingAs($owner)->post("/conversations/{$g->id}/owner/{$member->id}")
        ->assertSessionHasNoErrors();

    $g = $g->fresh();

    expect($g->owner_id)->toBe($member->id)
        ->and(roleOf($g, $member))->toBe('admin')
        // The former owner keeps admin, and loses nothing else.
        ->and(roleOf($g, $owner))->toBe('admin');
});

it('refuses anyone but the owner transferring ownership', function () {
    [$owner, $admin, $member] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$admin, $member]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($admin)->post("/conversations/{$g->id}/owner/{$member->id}")->assertForbidden();
});

it('lets admins rename and flip members_can_add, but only the owner delegates promotion', function () {
    [$owner, $admin] = User::factory()->count(2)->create();
    $g = group('Ops', $owner, [$admin]);
    $g->participants()->updateExistingPivot($admin->id, ['role' => ConversationRole::Admin->value]);

    $this->actingAs($admin)->patch("/conversations/{$g->id}", ['name' => 'Renamed', 'members_can_add' => true])
        ->assertSessionHasNoErrors();

    expect($g->fresh()->name)->toBe('Renamed')
        ->and($g->fresh()->members_can_add)->toBeTrue();

    $this->actingAs($admin)->patch("/conversations/{$g->id}", ['admins_can_promote' => true])
        ->assertForbidden();

    $this->actingAs($owner)->patch("/conversations/{$g->id}", ['admins_can_promote' => true])
        ->assertSessionHasNoErrors();

    expect($g->fresh()->admins_can_promote)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Direct chats refuse all of it
|--------------------------------------------------------------------------
*/

it('refuses every group action on a direct chat', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $dm = Conversation::findOrCreateDirect($a, $b);

    $this->actingAs($a)->post("/conversations/{$dm->id}/members", ['usernames' => [$c->username]])->assertForbidden();
    $this->actingAs($a)->patch("/conversations/{$dm->id}/members/{$b->id}", ['role' => 'admin'])->assertForbidden();
    $this->actingAs($a)->delete("/conversations/{$dm->id}/members/{$b->id}")->assertForbidden();
    $this->actingAs($a)->post("/conversations/{$dm->id}/owner/{$b->id}")->assertForbidden();
    $this->actingAs($a)->patch("/conversations/{$dm->id}", ['name' => 'nope'])->assertForbidden();
    $this->actingAs($a)->delete("/conversations/{$dm->id}/membership")->assertForbidden();

    expect($dm->fresh()->participants)->toHaveCount(2);
});

it('refuses a non-member entirely', function () {
    [$owner, $outsider, $target] = User::factory()->count(3)->create();
    $g = group('Ops', $owner, [$target]);

    $this->actingAs($outsider)->post("/conversations/{$g->id}/members", ['usernames' => ['whoever']])->assertForbidden();
    $this->actingAs($outsider)->delete("/conversations/{$g->id}/members/{$target->id}")->assertForbidden();
    $this->actingAs($outsider)->delete("/conversations/{$g->id}/membership")->assertForbidden();
});
