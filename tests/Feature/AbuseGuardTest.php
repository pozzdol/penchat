<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Encryption at rest
|--------------------------------------------------------------------------
*/

it('leaves nothing readable in the database', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    say($dm, $me, 'the account number is 1234');
    $g = group('Ridge Crew', $me);

    $rawBody = DB::table('messages')->whereNotNull('body')->value('body');
    $rawName = DB::table('conversations')->whereNotNull('name')->value('name');

    expect($rawBody)->not->toContain('account number')
        ->and($rawName)->not->toContain('Ridge')
        ->and(Crypt::decryptString($rawBody))->toBe('the account number is 1234')
        ->and($g->fresh()->name)->toBe('Ridge Crew');
});

/**
 * A ten-character group name encrypts to 228, so the column had to stop being
 * varchar(255) before anything was written to it. This is the length the UI
 * actually allows.
 */
it('survives a group name at the length the form permits', function () {
    $me = User::factory()->create();
    $long = str_repeat('a', 60);

    $g = group($long, $me);

    expect($g->fresh()->name)->toBe($long)
        ->and(strlen(DB::table('conversations')->value('name')))->toBeGreaterThan(255);
});

/*
|--------------------------------------------------------------------------
| Throttle, then suspend
|--------------------------------------------------------------------------
*/

function flood(User $sender, Conversation $conversation, int $times): void
{
    foreach (range(1, $times) as $i) {
        test()->actingAs($sender)->post("/conversations/{$conversation->id}/messages", ['body' => "m{$i}"]);
    }
}

it('refuses a message once the rate is plainly not human, and writes no row', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    flood($me, $dm, 30);
    expect(Message::count())->toBe(30);

    $this->actingAs($me)
        ->post("/conversations/{$dm->id}/messages", ['body' => 'one too many'])
        ->assertSessionHasErrors('body');

    expect(Message::count())->toBe(30);
});

/** One burst is a person having a moment. Three is not. */
it('does not suspend on a single burst', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    flood($me, $dm, 35);

    expect($me->fresh()->isSuspended())->toBeFalse();
});

it('suspends once the ceiling keeps being hit, and lifts itself', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    // Three separate occasions, not one long one.
    foreach (range(1, 3) as $occasion) {
        flood($me, $dm, 31);
        $this->travel(2)->minutes();
    }

    $me->refresh();
    expect($me->isSuspended())->toBeTrue()
        ->and($me->suspended_until->diffInMinutes(now()))->toBeLessThanOrEqual(15);

    // Nobody has to lift it — that is the whole point, since nobody could.
    $this->travel(16)->minutes();
    expect($me->fresh()->isSuspended())->toBeFalse();
});

it('reaches further up the ladder when it keeps happening', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $earn = function () use ($me, $dm) {
        foreach (range(1, 3) as $occasion) {
            flood($me, $dm, 31);
            test()->travel(2)->minutes();
        }

        return now()->diffInMinutes($me->fresh()->suspended_until);
    };

    $first = $earn();
    $this->travel(2)->hours();
    $second = $earn();

    expect($second)->toBeGreaterThan($first);
});

/*
|--------------------------------------------------------------------------
| What a suspension actually does
|--------------------------------------------------------------------------
*/

function suspended(): User
{
    $user = User::factory()->create();
    $user->forceFill(['suspended_until' => now()->addHour()])->save();

    return $user;
}

it('stops a suspended account writing anything other people would see', function () {
    $me = suspended();
    $other = User::factory()->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $mine = say($dm, $me, 'before the pause');

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'blocked'])
        ->assertSessionHasErrors('suspended');
    $this->actingAs($me)->patch("/messages/{$mine->id}", ['body' => 'blocked'])
        ->assertSessionHasErrors('suspended');
    $this->actingAs($me)->post('/conversations', ['name' => 'Blocked'])
        ->assertSessionHasErrors('suspended');
    $this->actingAs($me)->post('/conversations/direct', ['username' => $other->username])
        ->assertSessionHasErrors('suspended');

    expect($dm->fresh()->messages()->count())->toBe(1)
        ->and($mine->fresh()->body)->toBe('before the pause');
});

/**
 * Read-only, not locked out. Delivery acks especially: freezing those would
 * stall everyone else's ticks as a side effect of punishing one person.
 */
it('leaves a suspended account able to read, catch up and walk away', function () {
    $me = suspended();
    $other = User::factory()->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $theirs = say($dm, $other, 'still readable');

    $this->actingAs($me)->get("/c/{$dm->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 1));

    $this->actingAs($me)->post('/delivered')->assertSessionHasNoErrors();
    $this->actingAs($me)->patch("/conversations/{$dm->id}/read", ['message_id' => $theirs->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($me)->delete("/messages/{$theirs->id}/mine")->assertSessionHasNoErrors();
    $this->actingAs($me)->delete("/conversations/{$dm->id}/history")->assertSessionHasNoErrors();
});

it('lets an expired suspension write again without anyone lifting it', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $me->forceFill(['suspended_until' => now()->subMinute()])->save();
    $dm = Conversation::findOrCreateDirect($me, $other);

    $this->actingAs($me)->post("/conversations/{$dm->id}/messages", ['body' => 'back'])
        ->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| Consent to be added
|--------------------------------------------------------------------------
*/

it('refuses to build a group out of strangers', function () {
    [$me, $stranger] = User::factory()->count(2)->create();

    $this->actingAs($me)->post('/conversations', [
        'name' => 'Ridge Crew',
        'usernames' => [$stranger->username],
    ])->assertSessionHasErrors('usernames');

    expect(Conversation::where('type', 'group')->count())->toBe(0);
});

it('refuses to add a stranger to an existing group', function () {
    [$owner, $stranger] = User::factory()->count(2)->create();
    $g = group('Ops', $owner);

    $this->actingAs($owner)->post("/conversations/{$g->id}/members", [
        'usernames' => [$stranger->username],
    ])->assertSessionHasErrors('usernames');

    expect($g->fresh()->participants)->toHaveCount(1);
});

it('allows it once they have met, by direct chat or by a shared group', function () {
    [$me, $viaDm, $viaGroup, $newcomer] = User::factory()->count(4)->create();

    acquainted($me, $viaDm);
    $shared = group('Shared', $me, [$viaGroup]);

    $this->actingAs($me)->post('/conversations', [
        'name' => 'Ridge Crew',
        'usernames' => [$viaDm->username, $viaGroup->username],
    ])->assertSessionHasNoErrors();

    // And the rule keeps applying to the newcomer nobody has met.
    $this->actingAs($me)->post("/conversations/{$shared->id}/members", [
        'usernames' => [$newcomer->username],
    ])->assertSessionHasErrors('usernames');
});

/** A group of one is still allowed — you cannot be a stranger to yourself. */
it('lets someone build the room before they fill it', function () {
    $me = User::factory()->create();

    $this->actingAs($me)->post('/conversations', ['name' => 'Notes to self'])
        ->assertSessionHasNoErrors();
});
