<?php

use App\Models\User;

it('creates a verified account from a verified address and signs in', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => '  Ana Lima ', 'username' => ' @Ana_Lima '])
        ->assertRedirect('/')
        ->assertSessionMissing('otp.verified_email');

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->name)->toBe('Ana Lima')
        ->and($user->username)->toBe('ana_lima')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('refuses to create an account without a verified address', function () {
    $this->post('/login/name', ['name' => 'Ana', 'username' => 'ana'])->assertRedirect('/login');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('refuses a stale verification', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->subMinutes(11)->getTimestamp()])
        ->post('/login/name', ['name' => 'Ana', 'username' => 'ana'])
        ->assertRedirect('/login')
        ->assertSessionMissing('otp.verified_email');

    expect(User::count())->toBe(0);
});

it('validates the name', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'A', 'username' => 'ana'])
        ->assertSessionHasErrors('name');

    expect(User::count())->toBe(0);
});

it('tolerates two tabs completing the same sign-up', function () {
    User::factory()->create(['email' => 'new@example.com', 'name' => 'First Tab']);

    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'Second Tab', 'username' => 'secondtab'])
        ->assertRedirect('/');

    expect(User::where('email', 'new@example.com')->count())->toBe(1);
    $this->assertAuthenticated();
});

it('requires a username', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'Ana'])
        ->assertSessionHasErrors('username');

    expect(User::count())->toBe(0);
});

it('rejects a username that is not a plain lowercase handle', function (string $username) {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'Ana', 'username' => $username])
        ->assertSessionHasErrors('username');

    expect(User::count())->toBe(0);
})->with([
    'too short' => 'an',
    'starts with a digit' => '1ana',
    'starts with an underscore' => '_ana',
    'has a dash' => 'an-a',
    'too long' => 'aaaaaaaaaaaaaaaaaaaaa',
]);

it('rejects a username someone already holds, whatever the casing', function () {
    User::factory()->create(['username' => 'ana']);

    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'Ana', 'username' => 'ANA'])
        ->assertSessionHasErrors(['username' => 'That username is taken.']);
});
