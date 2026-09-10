<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

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

/*
|--------------------------------------------------------------------------
| What it costs to guess
|--------------------------------------------------------------------------
|
| Burning a code after five wrong guesses is the right defence against a
| brute force, and on its own it is also a weapon: anyone may put a known
| address into their own session and destroy that person's code five guesses
| at a time. The per-address budget is what makes guessing cost the guesser
| something. These tests are about that budget, not about the code.
|
*/

/** Any six digits that are not the real code. */
function otherThan(string $code): string
{
    return $code === '000000' ? '000001' : '000000';
}

/** One guess at `$email`'s code, made from `$ip`. */
function guessFrom(string $ip, string $email, string $code)
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withSession(['otp.email' => $email])
        ->post('/login/code', ['code' => $code]);
}

it('refuses more guesses once one address has spent its budget', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 10) as $_) {
        guessFrom('203.0.113.9', 'ana@example.com', '000000')
            ->assertSessionHasErrors(['code' => 'That code is not right or has expired.']);
    }

    guessFrom('203.0.113.9', 'ana@example.com', '000000')
        ->assertSessionHasErrors(['code' => 'Too many attempts. Try again in 10 minutes.']);
});

/**
 * A guesser who has run out keeps paying. Without this the per-address budget
 * is unreachable: five wrong guesses exhaust the per-account counter, every
 * later attempt returns through that branch, and the guesser's own budget
 * never moves — which is exactly how the first version of this was wrong.
 */
it('charges a guess even after the account counter is spent', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 7) as $_) {
        guessFrom('203.0.113.9', 'ana@example.com', '000000');
    }

    expect(RateLimiter::attempts('otp-verify:ip:203.0.113.9'))->toBe(7);
});

/**
 * A new code has to come with a new five guesses, or the counter is a
 * ten-minute lock on the address rather than on the code — and freezing
 * somebody out would cost five wrong guesses and no more.
 */
it('gives a freshly requested code its own five guesses', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $first = requestCode('ana@example.com');

    foreach (range(1, 5) as $_) {
        guessFrom('198.51.100.4', 'ana@example.com', otherThan($first));
    }

    $second = requestCode('ana@example.com');

    guessFrom('198.51.100.4', 'ana@example.com', $second)->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

/**
 * The invariant the number is chosen for: ten failures is fewer than the
 * fifteen it takes to burn all three codes a person may request in a window,
 * so one address can never take away every chance she has to sign in.
 */
it('stops one address burning every code a person may request', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    // Two codes destroyed, five guesses each, and the budget is gone.
    foreach (range(1, 2) as $_) {
        $code = requestCode('ana@example.com');

        foreach (range(1, 5) as $_) {
            guessFrom('203.0.113.9', 'ana@example.com', otherThan($code));
        }

        expect(Cache::has('otp:'.hash('sha256', 'ana@example.com')))->toBeFalse();
    }

    // Her third code is out of that address's reach, and still works.
    $third = requestCode('ana@example.com');

    guessFrom('203.0.113.9', 'ana@example.com', otherThan($third))
        ->assertSessionHasErrors(['code' => 'Too many attempts. Try again in 10 minutes.']);

    expect(Cache::has('otp:'.hash('sha256', 'ana@example.com')))->toBeTrue();

    guessFrom('198.51.100.4', 'ana@example.com', $third)->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

/**
 * Only failures are counted. An office behind one address is full of people
 * signing in correctly, and none of them should pay for a guesser's budget.
 */
it('spends nothing when the code is right', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $code = requestCode('ana@example.com');

    guessFrom('203.0.113.9', 'ana@example.com', $code)->assertRedirect('/');

    expect(RateLimiter::attempts('otp-verify:ip:203.0.113.9'))->toBe(0);
});

/** The budget belongs to the guesser, not to the account being guessed at. */
it('does not let one guesser spend another address budget', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 10) as $_) {
        guessFrom('203.0.113.9', 'ana@example.com', '000000');
    }

    guessFrom('198.51.100.4', 'ana@example.com', '000000')
        ->assertSessionHasErrors(['code' => 'That code is not right or has expired.']);
});
