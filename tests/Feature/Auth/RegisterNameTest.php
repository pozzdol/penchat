<?php

use App\Models\User;

it('creates a verified account from a verified address and signs in', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => '  Ana Lima '])
        ->assertRedirect('/')
        ->assertSessionMissing('otp.verified_email');

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->name)->toBe('Ana Lima')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('refuses to create an account without a verified address', function () {
    $this->post('/login/name', ['name' => 'Ana'])->assertRedirect('/login');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('refuses a stale verification', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->subMinutes(11)->getTimestamp()])
        ->post('/login/name', ['name' => 'Ana'])
        ->assertRedirect('/login')
        ->assertSessionMissing('otp.verified_email');

    expect(User::count())->toBe(0);
});

it('validates the name', function () {
    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'A'])
        ->assertSessionHasErrors('name');

    expect(User::count())->toBe(0);
});

it('tolerates two tabs completing the same sign-up', function () {
    User::factory()->create(['email' => 'new@example.com', 'name' => 'First Tab']);

    $this->withSession(['otp.verified_email' => 'new@example.com', 'otp.verified_at' => now()->getTimestamp()])
        ->post('/login/name', ['name' => 'Second Tab'])
        ->assertRedirect('/');

    expect(User::where('email', 'new@example.com')->count())->toBe(1);
    $this->assertAuthenticated();
});
