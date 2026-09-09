<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterNameRequest;
use App\Http\Requests\Auth\SendCodeRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Passwordless sign-in: email -> code -> (name, if new) -> in. Login and
 * registration are one flow; which step to show is derived from the session,
 * so there is one GET and no URL can be visited "out of order".
 */
class LoginController extends Controller
{
    private const NAME_STEP_GRACE_SECONDS = 600;

    public function __construct(private LoginCode $codes) {}

    public function show(Request $request): Response
    {
        $session = $request->session();

        $step = match (true) {
            $session->has('otp.verified_email') => 'name',
            $session->has('otp.email') => 'code',
            default => 'email',
        };

        return Inertia::render('auth/login', [
            'step' => $step,
            'email' => $session->get('otp.verified_email') ?? $session->get('otp.email'),
        ]);
    }

    /** Also serves "send a new code": the same limits apply. */
    public function sendCode(SendCodeRequest $request): RedirectResponse
    {
        $email = $request->validated('email');

        $this->codes->send($email, (string) $request->ip());

        $request->session()->forget(['otp.verified_email', 'otp.verified_at']);
        $request->session()->put('otp.email', $email);

        return to_route('login');
    }

    public function verify(VerifyCodeRequest $request): RedirectResponse
    {
        $email = $request->session()->get('otp.email');

        if (! is_string($email)) {
            return to_route('login');
        }

        if (! $this->codes->verify($email, $request->validated('code'))) {
            // One message for wrong, expired and burned: nothing to enumerate.
            throw ValidationException::withMessages([
                'code' => 'That code is not right or has expired.',
            ]);
        }

        $request->session()->forget('otp.email');

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            return $this->signIn($request, $user);
        }

        // Existence is revealed only after the code is proven — by then the
        // requester controls the inbox, so there is nothing left to leak.
        $request->session()->put([
            'otp.verified_email' => $email,
            'otp.verified_at' => now()->getTimestamp(),
        ]);

        return to_route('login');
    }

    public function register(RegisterNameRequest $request): RedirectResponse
    {
        $email = $request->session()->get('otp.verified_email');
        $verifiedAt = $request->session()->get('otp.verified_at');

        $fresh = is_string($email)
            && is_int($verifiedAt)
            && now()->getTimestamp() - $verifiedAt <= self::NAME_STEP_GRACE_SECONDS;

        if (! $fresh) {
            $request->session()->forget(['otp.verified_email', 'otp.verified_at']);

            return to_route('login');
        }

        // firstOrCreate: two tabs finishing the same sign-up resolve on the
        // users.email unique index instead of a 500.
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $request->validated('name'), 'email_verified_at' => now()],
        );

        return $this->signIn($request, $user);
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(['otp.email', 'otp.verified_email', 'otp.verified_at']);

        return to_route('login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }

    private function signIn(Request $request, User $user): RedirectResponse
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $request->session()->forget(['otp.email', 'otp.verified_email', 'otp.verified_at']);

        // login() regenerates the session id itself (SessionGuard::updateSession).
        Auth::login($user, remember: true);

        return redirect()->intended(route('chat.index'));
    }
}
