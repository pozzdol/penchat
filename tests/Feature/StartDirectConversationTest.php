<?php

use App\Models\Conversation;
use App\Models\User;

it('opens a direct chat with whoever holds the username', function () {
    $me = User::factory()->create(['username' => 'fikri']);
    $them = User::factory()->create(['username' => 'luis']);

    $response = $this->actingAs($me)->post('/conversations/direct', ['username' => 'luis']);

    $conversation = Conversation::sole();

    $response->assertRedirect("/c/{$conversation->id}")->assertSessionHasNoErrors();

    expect($conversation->direct_key)->toBe(min($me->id, $them->id).'-'.max($me->id, $them->id))
        ->and($conversation->participants()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$me->id, $them->id])->sort()->values()->all());
});

it('returns to the same conversation instead of making a second one', function () {
    $me = User::factory()->create(['username' => 'fikri']);
    User::factory()->create(['username' => 'luis']);

    $first = $this->actingAs($me)->post('/conversations/direct', ['username' => 'luis']);
    $again = $this->actingAs($me)->post('/conversations/direct', ['username' => 'luis']);

    expect($again->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(Conversation::count())->toBe(1);
});

it('accepts a pasted handle with an @ and any casing', function (string $typed) {
    $me = User::factory()->create(['username' => 'fikri']);
    User::factory()->create(['username' => 'luis']);

    $this->actingAs($me)->post('/conversations/direct', ['username' => $typed])
        ->assertSessionHasNoErrors();

    expect(Conversation::count())->toBe(1);
})->with(['@luis', 'LUIS', '  @Luis  ']);

it('reports an unknown username without saying anything else', function () {
    $me = User::factory()->create(['username' => 'fikri']);

    $this->actingAs($me)->post('/conversations/direct', ['username' => 'nobody'])
        ->assertSessionHasErrors(['username' => 'No one has that username.']);

    expect(Conversation::count())->toBe(0);
});

it('gives the same answer for a malformed handle as for a missing one', function () {
    $me = User::factory()->create(['username' => 'fikri']);

    $this->actingAs($me)->post('/conversations/direct', ['username' => 'no'])
        ->assertSessionHasErrors(['username' => 'No one has that username.']);
});

it('refuses a chat with yourself', function () {
    $me = User::factory()->create(['username' => 'fikri']);

    $this->actingAs($me)->post('/conversations/direct', ['username' => 'FIKRI'])
        ->assertSessionHasErrors('username');

    expect(Conversation::count())->toBe(0);
});

it('caps lookups so the directory cannot be probed in bulk', function () {
    $me = User::factory()->create(['username' => 'fikri']);

    foreach (range(1, 20) as $i) {
        $this->actingAs($me)->post('/conversations/direct', ['username' => "ghost{$i}"])
            ->assertSessionHasErrors(['username' => 'No one has that username.']);
    }

    $this->actingAs($me)->post('/conversations/direct', ['username' => 'ghost21'])
        ->assertSessionHasErrors(['username' => 'Too many lookups. Try again in a moment.']);
});

it('is closed to guests', function () {
    User::factory()->create(['username' => 'luis']);

    $this->post('/conversations/direct', ['username' => 'luis'])->assertRedirect('/login');

    expect(Conversation::count())->toBe(0);
});
