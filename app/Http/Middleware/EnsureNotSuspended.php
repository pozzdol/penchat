<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account is read-only, not locked out.
 *
 * It is applied to the routes that produce content or reach other people, and
 * deliberately not to reading, marking read, or delivery acks — freezing
 * those would stall everyone else's ticks as a side effect of punishing one
 * person. Nor to clearing, deleting your own copy, or leaving: being unable
 * to walk away from a conversation is not a sanction anybody asked for.
 *
 * `ValidationException` rather than a 403, because a 403 surfaces as an
 * Inertia error modal while this lands under the composer where the person is
 * already looking. That is the choice this codebase made for its other limits.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();

        if ($user?->isSuspended()) {
            throw ValidationException::withMessages([
                'suspended' => 'Your account is paused until '
                    .$user->suspended_until->format('H:i').' for sending too quickly. You can still read.',
            ]);
        }

        return $next($request);
    }
}
