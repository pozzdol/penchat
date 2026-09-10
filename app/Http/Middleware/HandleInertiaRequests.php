<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            /*
             * The browser needs this to call `pushManager.subscribe()`. It
             * travels as a prop rather than a `VITE_` variable on purpose:
             * anything compiled into the bundle only changes after
             * `bun run build`, and a key that silently keeps its old value
             * after a rotation is the exact failure AGENTS.md warns about in
             * the Reverb env vars.
             *
             * Null when Web Push is not configured, which is how the client
             * knows not to render a control that could never work.
             */
            'vapidPublicKey' => fn () => config('services.webpush.public_key') ?: null,
        ];
    }
}
