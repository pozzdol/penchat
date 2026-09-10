<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Http\Requests\StartDirectConversationRequest;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateConversationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Support\UsernameResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $this->mustHaveMet($request->user(), $members->all());

        $conversation = Conversation::createGroup(
            $request->validated('name'),
            $request->user(),
            $members->all(),
        );

        // The people who were added did not ask for this page, so the only way
        // the group reaches their sidebar is the signal.
        ConversationTouched::dispatch($conversation);

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

        ConversationTouched::dispatch($conversation);

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

    /**
     * Take a direct chat off the viewer's list, and — only if they asked — off
     * the other person's too. Route-level `can:deleteChat` has run, and it
     * refuses on a group: a group is left, not deleted, so that one member
     * cannot destroy everyone else's history.
     *
     * Nothing is erased. Both sides keep every row; the pointers decide who can
     * still see them. That is what makes this survivable when one party clears
     * a conversation the other still wants.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation->clearFor($user, hide: true);

        if ($request->boolean('also_for_other')) {
            $other = $conversation->participants()
                ->whereKeyNot($user->id)
                ->first();

            if ($other) {
                $conversation->clearFor($other, hide: true);

                // Only here. Deleting your own copy changed nothing for anyone
                // else, and you are already holding the response.
                ConversationTouched::dispatch($conversation);
            }
        }

        // The thread the viewer was reading is gone from their list; sending
        // them back to it would show an empty room they cannot find again.
        return redirect()->route('chat.index');
    }

    /**
     * You can only put someone in a group if you have already met them here.
     *
     * This is consent rather than punishment, and it is the difference
     * between preventing an unwanted group and reporting one afterwards. A
     * stranger has to reach you through a direct chat first — something you
     * can delete and ignore — before they can add you to anything.
     *
     * It lives at the HTTP boundary rather than in `Conversation::createGroup`
     * on purpose: the model stays an unguarded primitive for seeders and
     * tests, and every path a user can reach comes through a controller.
     *
     * @param  list<User>  $targets
     *
     * @throws ValidationException
     */
    private function mustHaveMet(User $actor, array $targets): void
    {
        foreach ($targets as $target) {
            if ($target->is($actor) || $actor->sharesConversationWith($target)) {
                continue;
            }

            throw ValidationException::withMessages([
                'usernames' => "You have not spoken to @{$target->username} yet. Start a chat with them first.",
            ]);
        }
    }
}
