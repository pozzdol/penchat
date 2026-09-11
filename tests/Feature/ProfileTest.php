<?php

use App\Events\ConversationTouched;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('changes the name and the username', function () {
    $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana Dewi', 'username' => 'anadewi'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Ana Dewi')
        ->and($user->fresh()->username)->toBe('anadewi');
});

/**
 * The same normalisation registration does, from the same place on the model.
 * A handle typed with its @ and a capital is the handle the person meant.
 */
it('normalises the username the way registration does', function () {
    $user = User::factory()->create(['username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana', 'username' => ' @AnaDewi '])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->username)->toBe('anadewi');
});

/** Saving the form untouched must not trip over the handle you already hold. */
it('lets you save without changing your own username', function () {
    $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana Dewi', 'username' => 'ana'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Ana Dewi');
});

it('refuses a username somebody else holds', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana', 'username' => 'taken'])
        ->assertSessionHasErrors(['username' => 'That username is taken.']);

    expect($user->fresh()->username)->toBe('ana');
});

it('refuses a username that is the wrong shape', function () {
    $user = User::factory()->create(['username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana', 'username' => '1nope'])
        ->assertSessionHasErrors('username');

    expect($user->fresh()->username)->toBe('ana');
});

it('refuses a name that is too short', function () {
    $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana']);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'A', 'username' => 'ana'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->name)->toBe('Ana');
});

/**
 * A rename has to reach the sidebars it appears in. Without this everyone
 * else carries the old name until they happen to reload — the exact "refresh
 * before it shows up" behaviour the realtime layer exists to avoid.
 */
it('tells the rooms you are in that something changed', function () {
    Event::fake([ConversationTouched::class]);

    $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana']);
    $other = User::factory()->create();
    $dm = acquainted($user, $other);
    $room = group('Standup', $other, [$user]);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana Dewi', 'username' => 'ana'])
        ->assertSessionHasNoErrors();

    foreach ([$dm, $room] as $conversation) {
        Event::assertDispatched(
            ConversationTouched::class,
            fn (ConversationTouched $e) => $e->conversation->id === $conversation->id,
        );
    }
});

/** A pointer that did not move announces nothing, and neither does a name. */
it('says nothing when the form is saved unchanged', function () {
    Event::fake([ConversationTouched::class]);

    $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana']);
    acquainted($user, User::factory()->create());

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana', 'username' => 'ana'])
        ->assertSessionHasNoErrors();

    Event::assertNotDispatched(ConversationTouched::class);
});

/**
 * Your name sits in every participant's sidebar, so editing it is putting
 * content in front of other people — which is the one thing a suspension
 * stops. Renaming yourself would otherwise be a way straight around it.
 */
it('refuses a suspended account', function () {
    $user = User::factory()->create([
        'name' => 'Ana',
        'username' => 'ana',
        'suspended_until' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Ana Dewi', 'username' => 'ana']);

    expect($user->fresh()->name)->toBe('Ana');
});

it('turns a guest away', function () {
    $this->patch('/settings/profile', ['name' => 'Ana', 'username' => 'ana'])
        ->assertRedirect('/login');
});
