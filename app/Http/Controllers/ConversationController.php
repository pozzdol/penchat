<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartDirectConversationRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateConversationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Support\UsernameResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    /** Lookups per minute, per signed-in user. */
    private const LOOKUPS_PER_MINUTE = 20;

    /** Create a group. The creator owns it and is its first admin. */
    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $members = UsernameResolver::resolve($request->validated('usernames'));

        $conversation = Conversation::createGroup(
            $request->validated('name'),
            $request->user(),
            $members->all(),
        );

        return to_route('chat.show', $conversation);
    }

    /**
     * Rename a group or flip its switches. Route-level `can:updateSettings`
     * covers name and members_can_add; delegating the owner's own authority is
     * checked here because only the owner may do it.
     */
    public function update(UpdateConversationRequest $request, Conversation $conversation): RedirectResponse
    {
        abort_unless($conversation->isGroup(), 403);

        $data = $request->validated();

        if (array_key_exists('admins_can_promote', $data)) {
            Gate::authorize('updateOwnerSettings', $conversation);
        }

        $conversation->fill($data)->save();

        return back();
    }

    /**
     * Open the direct chat with whoever holds this username, creating it on
     * first contact. There is no friend request: knowing the handle is the
     * introduction, the way it works in Telegram.
     *
     * An exact-match lookup does confirm that a username exists — that is
     * inherent to being findable at all. What it must not allow is bulk
     * probing, hence the limiter.
     */
    public function direct(StartDirectConversationRequest $request): RedirectResponse
    {
        $me = $request->user();

        $limiter = 'direct-lookup:'.$me->id;

        if (RateLimiter::tooManyAttempts($limiter, self::LOOKUPS_PER_MINUTE)) {
            throw ValidationException::withMessages([
                'username' => 'Too many lookups. Try again in a moment.',
            ]);
        }

        RateLimiter::hit($limiter, 60);

        $username = $request->validated('username');

        if ($username === $me->username) {
            throw ValidationException::withMessages([
                'username' => 'That is your own username.',
            ]);
        }

        $them = User::query()->where('username', $username)->first();

        if (! $them) {
            throw ValidationException::withMessages([
                'username' => 'No one has that username.',
            ]);
        }

        $conversation = Conversation::findOrCreateDirect($me, $them);

        return to_route('chat.show', $conversation);
    }
}
