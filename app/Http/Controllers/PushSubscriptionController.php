<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A browser registering, or withdrawing, its willingness to be interrupted.
 *
 * Not Inertia responses: these are background calls from a service-worker
 * registration, not navigations, and returning `back()` would make every
 * permission toggle re-render the whole page's props.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Upsert on the endpoint, because the browser hands back the same one
     * every time until site data is cleared. Without that, every page load
     * that re-checks its subscription would add a row and the same device
     * would be pushed to five times.
     *
     * The upsert also *reassigns* the row to the current user, which is the
     * point rather than an oversight: a shared browser signed out and signed
     * back in as someone else is the same endpoint belonging to a different
     * person, and the previous owner must stop receiving on it.
     */
    public function store(StorePushSubscriptionRequest $request): Response
    {
        PushSubscription::updateOrCreate(
            ['endpoint' => $request->validated('endpoint')],
            [
                'user_id' => $request->user()->id,
                'public_key' => $request->validated('keys.p256dh'),
                'auth_token' => $request->validated('keys.auth'),
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
            ],
        );

        return response()->noContent();
    }

    /**
     * Scoped to the caller's own rows. An endpoint is unguessable, but
     * "unguessable" is not an authorization rule, and letting one signed-in
     * user delete another's subscription by id would be a way to silence
     * somebody.
     */
    public function destroy(Request $request): Response
    {
        $endpoint = (string) $request->input('endpoint');

        if ($endpoint !== '') {
            $request->user()->pushSubscriptions()
                ->where('endpoint', $endpoint)
                ->delete();
        }

        return response()->noContent();
    }
}
