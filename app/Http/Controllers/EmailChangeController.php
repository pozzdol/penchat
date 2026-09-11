<?php

namespace App\Http\Controllers;

use App\Auth\LoginCode;
use App\Http\Requests\SendEmailChangeCodeRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Changing the address you sign in with.
 *
 * Two steps, and the shape is not a preference. Sign-in here is passwordless:
 * the email *is* the credential, there is no password to fall back on and no
 * administrator in this app to appeal to (`ConversationRole` is per-group).
 * A single text field would mean one mistyped character locks a person out of
 * their account permanently, with nobody able to undo it.
 *
 * So the code goes to the **new** address and the row only moves once it comes
 * back. Proving control of the inbox is the entire point; anything less is a
 * form that can destroy an account by typo.
 *
 * Deliberately outside `not-suspended`. A suspension stops you putting content
 * in front of other people, and an email address is visible to nobody —
 * changing it takes nothing away from anyone and does not lift the suspension,
 * which lives on the user row.
 *
 * **Known gap, stated rather than hidden:** the old address is not told that
 * this happened. Someone holding a stolen session could move the account to an
 * address of their own and make their access permanent. Closing it properly
 * means a second mail template and a second code to the old inbox; at five
 * users that was judged not worth the friction, and it is the first thing to
 * revisit if this app ever grows past the people who know each other.
 */
class EmailChangeController extends Controller
{
    /** Where the pending address waits. Session, so it cannot outlive the
     *  browser that started it — the person is being verified, not the row. */
    private const PENDING = 'email_change.pending';

    public function send(SendEmailChangeCodeRequest $request, LoginCode $codes): RedirectResponse
    {
        $email = $request->validated('email');

        if ($email === $request->user()->email) {
            throw ValidationException::withMessages([
                'email' => 'That is already your address.',
            ]);
        }

        // Throws on its own limits, which are the sign-in limits: three codes
        // per address and ten per IP in ten minutes.
        $codes->send($email, (string) $request->ip());

        $request->session()->put(self::PENDING, $email);

        return back();
    }

    public function confirm(Request $request, LoginCode $codes): RedirectResponse
    {
        $email = $request->session()->get(self::PENDING);

        if (! is_string($email)) {
            return back();
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $codes->verify($email, (string) $request->input('code'), (string) $request->ip())) {
            // One message for wrong, expired and burned — the same refusal
            // sign-in gives, and for the same reason.
            throw ValidationException::withMessages([
                'code' => 'That code is not right or has expired.',
            ]);
        }

        try {
            $request->user()->forceFill([
                'email' => $email,
                // Proven a moment ago, by the code that just came back.
                'email_verified_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Free when it validated, taken by the time we saved. Rare enough
            // to be a race and cheap enough to just say so.
            $request->session()->forget(self::PENDING);

            throw ValidationException::withMessages([
                'email' => 'That address was taken while you were confirming it.',
            ]);
        }

        $request->session()->forget(self::PENDING);

        return back();
    }

    /** Walking away. The code is burned rather than left alive for its ten
     *  minutes — nothing should still be able to move an address nobody is
     *  waiting on. */
    public function cancel(Request $request, LoginCode $codes): RedirectResponse
    {
        $email = $request->session()->pull(self::PENDING);

        if (is_string($email)) {
            $codes->burn($email);
        }

        return back();
    }

    /** What the settings page needs to know about a change in flight. */
    public static function pendingFor(Request $request): ?string
    {
        $pending = $request->session()->get(self::PENDING);

        return is_string($pending) ? $pending : null;
    }
}
