<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** Requests a code for $email and returns the six digits that were mailed. */
function requestCode(string $email): string
{
    Mail::fake();
    test()->post('/login/email', ['email' => $email])->assertSessionHasNoErrors();

    return Mail::sent(LoginCodeMail::class)->first()->code;
}

it('signs an existing user in with the right code and remembers them', function () {
    $user = User::factory()->create(['email' => 'ana@example.com', 'email_verified_at' => null]);
    $code = requestCode('ana@example.com');

    $response = $this->post('/login/code', ['code' => $code]);

    $response->assertRedirect('/')->assertSessionMissing('otp.email');
    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(Cache::has('otp:'.hash('sha256', 'ana@example.com')))->toBeFalse();

    $remember = collect($response->headers->getCookies())
        ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($remember)->not->toBeNull();
});

it('sends a new email to the name step, still signed out', function () {
    $code = requestCode('new@example.com');

    $this->post('/login/code', ['code' => $code])
        ->assertRedirect('/login')
        ->assertSessionHas('otp.verified_email', 'new@example.com')
        ->assertSessionMissing('otp.email');

    $this->assertGuest();
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();

    $this->get('/login')->assertInertia(fn ($page) => $page->where('step', 'name'));
});

it('rejects a wrong code with a generic message', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $code = requestCode('ana@example.com');
    $wrong = $code === '000000' ? '000001' : '000000';

    $this->post('/login/code', ['code' => $wrong])
        ->assertSessionHasErrors(['code' => 'That code is not right or has expired.']);

    $this->assertGuest();
});

it('rejects an expired code', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $code = requestCode('ana@example.com');

    $this->travel(11)->minutes();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('cannot reuse a code after it has been accepted', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $code = requestCode('ana@example.com');

    $this->post('/login/code', ['code' => $code])->assertRedirect('/');
    $this->post('/logout');

    $this->withSession(['otp.email' => 'ana@example.com'])
        ->post('/login/code', ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('burns the code after five wrong guesses', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $code = requestCode('ana@example.com');
    $wrong = $code === '000000' ? '000001' : '000000';

    foreach (range(1, 5) as $_) {
        $this->post('/login/code', ['code' => $wrong])->assertSessionHasErrors('code');
    }

    // The right code no longer works either.
    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('goes back to the email step when there is no pending address', function () {
    $this->post('/login/code', ['code' => '123456'])->assertRedirect('/login');
    $this->assertGuest();
});

it('validates the code format before touching the cache', function () {
    $this->withSession(['otp.email' => 'ana@example.com'])
        ->post('/login/code', ['code' => '12ab'])
        ->assertSessionHasErrors('code');
});
