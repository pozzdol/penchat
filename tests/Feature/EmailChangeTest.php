<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Changing the address you sign in with
|--------------------------------------------------------------------------
|
| Sign-in is passwordless, so this field is the credential. There is no
| password to fall back on and no administrator to appeal to, which is why the
| code goes to the *new* address and the row only moves once it comes back. A
| single-step form here would be able to destroy an account by typo.
|
*/

/** Asks to move to $email and returns the six digits that were mailed there. */
function requestChange(User $user, string $email): string
{
    Mail::fake();

    test()->actingAs($user)
        ->post('/settings/email', ['email' => $email])
        ->assertSessionHasNoErrors();

    return Mail::sent(LoginCodeMail::class)->first()->code;
}

it('sends the code to the new address, not the old one', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    Mail::fake();
    $this->actingAs($user)->post('/settings/email', ['email' => 'new@example.com']);

    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) {
        return $mail->hasTo('new@example.com') && ! $mail->hasTo('old@example.com');
    });
});

/** Nothing moves on the strength of asking. */
it('leaves the address alone until the code comes back', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    requestChange($user, 'new@example.com');

    expect($user->fresh()->email)->toBe('old@example.com');
});

it('moves the address once the code is confirmed', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => null]);
    $code = requestChange($user, 'new@example.com');

    $this->actingAs($user)
        ->post('/settings/email/confirm', ['code' => $code])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('new@example.com')
        // Proven a moment ago by the code that just came back.
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('refuses a wrong code and keeps the old address', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $code = requestChange($user, 'new@example.com');
    $wrong = $code === '000000' ? '000001' : '000000';

    $this->actingAs($user)
        ->post('/settings/email/confirm', ['code' => $wrong])
        ->assertSessionHasErrors(['code' => 'That code is not right or has expired.']);

    expect($user->fresh()->email)->toBe('old@example.com');
});

it('refuses an address another account already holds', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'old@example.com']);

    Mail::fake();

    $this->actingAs($user)
        ->post('/settings/email', ['email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
    expect($user->fresh()->email)->toBe('old@example.com');
});

it('refuses the address you already have', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Mail::fake();

    $this->actingAs($user)
        ->post('/settings/email', ['email' => 'mine@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

it('normalises the address the way sign-in does', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $code = requestChange($user, ' NEW@Example.COM ');

    $this->actingAs($user)->post('/settings/email/confirm', ['code' => $code]);

    expect($user->fresh()->email)->toBe('new@example.com');
});

/** A half-finished change has to survive a reload, or it is lost on a phone
 *  that switched apps to read the code. */
it('remembers the pending address across a page load', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestChange($user, 'new@example.com');

    $this->actingAs($user)
        ->get('/settings')
        ->assertInertia(fn ($page) => $page->where('account.pending_email', 'new@example.com'));
});

it('shows nothing pending when no change is in flight', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertInertia(fn ($page) => $page->where('account.pending_email', null));
});

/** Walking away has to actually end it, code and all. */
it('cancels a pending change and burns its code', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $code = requestChange($user, 'new@example.com');

    expect(Cache::has('otp:'.hash('sha256', 'new@example.com')))->toBeTrue();

    $this->actingAs($user)->delete('/settings/email')->assertRedirect();

    $this->actingAs($user)
        ->get('/settings')
        ->assertInertia(fn ($page) => $page->where('account.pending_email', null));

    /* Asserted against the cache, not by replaying the code: confirming after
       a cancel returns early on the empty session, so a replay would pass
       whether or not anything was actually burned. */
    expect(Cache::has('otp:'.hash('sha256', 'new@example.com')))->toBeFalse();

    $this->actingAs($user)->post('/settings/email/confirm', ['code' => $code]);

    expect($user->fresh()->email)->toBe('old@example.com');
});

/** Confirming without having asked is a no-op, not a crash. */
it('does nothing when no change was started', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->post('/settings/email/confirm', ['code' => '123456'])
        ->assertRedirect();

    expect($user->fresh()->email)->toBe('old@example.com');
});

/**
 * The limits are the sign-in limits, because it is the same code path. Three
 * per address in ten minutes, and this app has no second way in if somebody
 * floods an inbox with them.
 */
it('applies the sign-in rate limits to the codes it sends', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    Mail::fake();

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)
            ->post('/settings/email', ['email' => 'new@example.com'])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($user)
        ->post('/settings/email', ['email' => 'new@example.com'])
        ->assertSessionHasErrors('email');
});

/**
 * An email is visible to nobody, so changing it puts no content in front of
 * anyone — which is the only thing a suspension stops. It does not lift the
 * suspension either; that lives on the user row and travels with it.
 */
it('lets a suspended account change its address', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'suspended_until' => now()->addDay(),
    ]);

    $code = requestChange($user, 'new@example.com');
    $this->actingAs($user)->post('/settings/email/confirm', ['code' => $code]);

    expect($user->fresh()->email)->toBe('new@example.com')
        ->and($user->fresh()->isSuspended())->toBeTrue();
});

it('turns a guest away', function () {
    $this->post('/settings/email', ['email' => 'new@example.com'])->assertRedirect('/login');
    $this->post('/settings/email/confirm', ['code' => '123456'])->assertRedirect('/login');
});
