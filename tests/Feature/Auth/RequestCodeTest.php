<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('emails a six-digit code and remembers the address in the session', function () {
    $this->post('/login/email', ['email' => '  Ana@Example.COM '])
        ->assertRedirect('/login')
        ->assertSessionHas('otp.email', 'ana@example.com')
        ->assertSessionHasNoErrors();

    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) {
        expect($mail->code)->toMatch('/^\d{6}$/');

        return $mail->hasTo('ana@example.com');
    });

    expect(Cache::has('otp:'.hash('sha256', 'ana@example.com')))->toBeTrue();
});

it('shows the code step after a code was requested', function () {
    $this->post('/login/email', ['email' => 'ana@example.com']);

    $this->get('/login')->assertInertia(fn ($page) => $page
        ->component('auth/login')
        ->where('step', 'code')
        ->where('email', 'ana@example.com'));
});

it('responds identically for a new and an existing email', function () {
    User::factory()->create(['email' => 'known@example.com']);

    $known = $this->post('/login/email', ['email' => 'known@example.com']);
    $fresh = $this->post('/login/email', ['email' => 'unknown@example.com']);

    expect($fresh->getStatusCode())->toBe($known->getStatusCode())
        ->and($fresh->headers->get('Location'))->toBe($known->headers->get('Location'));

    Mail::assertSentCount(2);
});

it('allows three codes per address and refuses the fourth', function () {
    foreach (range(1, 3) as $_) {
        $this->post('/login/email', ['email' => 'ana@example.com'])->assertSessionHasNoErrors();
    }

    $this->post('/login/email', ['email' => 'ana@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertSentCount(3);
});

it('caps codes per ip across addresses', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/login/email', ['email' => "user{$i}@example.com"])->assertSessionHasNoErrors();
    }

    $this->post('/login/email', ['email' => 'user11@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertSentCount(10);
});

it('rejects an address that is not an email', function () {
    $this->post('/login/email', ['email' => 'not-an-email'])->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});
