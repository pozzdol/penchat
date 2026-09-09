<?php

use App\Models\User;

it('redirects guests to the sign-in page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('sends signed-in users away from the sign-in page', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('signs out and invalidates the session', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

it('clears a half-finished sign-in on restart', function () {
    $this->withSession(['otp.email' => 'ana@example.com'])
        ->post('/login/restart')
        ->assertRedirect('/login')
        ->assertSessionMissing('otp.email');
});
