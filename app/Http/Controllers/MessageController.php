<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Events\MessageSent;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\PushNotifier;
use App\Support\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    /**
     * Route-level `can:send,conversation` has already run.
     *
     * The transaction is not strictly needed for a bare message, but it is the
     * shape attachments need in Phase 3, and writing it now means that change
     * does not have to reopen this method.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();

        // Before the write, so a refused message leaves no row behind.
        SpamGuard::check($user);

        $quoted = $this->quotable($request->validated('reply_to_message_id'), $conversation, $user);

        $message = DB::transaction(function () use ($request, $conversation, $user, $quoted) {
            $message = $conversation->messages()->create([
                'user_id' => $user->id,
                'body' => $request->validated('body'),
                'reply_to_message_id' => $quoted?->id,
                // Snapshotted here, by the server, from a message this sender
                // was allowed to read. The client sends an id and never text.
                'reply_to_body' => $quoted?->body,
            ]);

            // You have plainly seen what you just replied to, and just as
            // plainly received it.
            $conversation->participants()->updateExistingPivot($user->id, [
                'last_read_message_id' => $message->id,
                'last_delivered_message_id' => $message->id,
            ]);

            return $message;
        });

        // After the commit, never inside it: a subscriber told about a message
        // the transaction then rolled back would show one that does not exist.
        $this->announce($conversation, $message);

        return back();
    }

    /**
     * Route-level `can:update,message` has already checked author and window.
     *
     * `forceFill` rather than `update`: `body` is fillable, `edited_at` is the
     * server's to set, and the two must move together or a silent edit is
     * possible.
     */
    public function update(UpdateMessageRequest $request, Message $message): RedirectResponse
    {
        $message->forceFill([
            'body' => $request->validated('body'),
            'edited_at' => now(),
        ])->save();

        // No payload. An edit of a message this viewer cleared or hid would
        // otherwise arrive on the channel and walk straight past the filter
        // that was hiding it; the reload asks the server, which knows.
        ConversationTouched::dispatch($message->conversation);

        return back();
    }

    /**
     * Delete for everyone. Route-level `can:deleteForEveryone,message` has run.
     *
     * The row survives as a tombstone — the thread shows that something stood
     * here — but the text does not. Keeping the body on disk behind a flag
     * would mean "deleted" only described the interface. Attachments follow
     * the same rule when Phase 3 adds them.
     */
    public function destroy(Message $message): RedirectResponse
    {
        $message->forceFill(['body' => null, 'deleted_at' => now()])->save();

        ConversationTouched::dispatch($message->conversation);

        return back();
    }

    /**
     * Delete for me. Route-level `can:deleteForMe,message` has run.
     *
     * `insertOrIgnore` on the composite primary key, so hiding the same message
     * twice is not an error — two clicks, or a retry, land on the same state.
     */
    public function destroyForMe(Request $request, Message $message): RedirectResponse
    {
        DB::table('message_user_deletions')->insertOrIgnore([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
        ]);

        // Not broadcast: this changed one person's copy, and that person is
        // the one holding the response.
        return back();
    }

    /**
     * The message being quoted, or null — and the only gate on it.
     *
     * The client hands over an id and the server copies the words, so an
     * unchecked id is a way to have the server read a message out of a
     * conversation the sender is not in and paste it into one they are. The
     * three conditions below are exactly the ones that decide whether they
     * could have seen it in the first place: same conversation, above their
     * cleared pointer, and not one they hid.
     *
     * An id that fails any of them is refused rather than silently dropped —
     * quietly sending the message without its quote would look like a bug to
     * the sender and hide a probe from everyone else.
     *
     * @throws ValidationException
     */
    private function quotable(?string $id, Conversation $conversation, User $user): ?Message
    {
        if ($id === null) {
            return null;
        }

        $cleared = (string) ($conversation->participants()
            ->whereKey($user->id)
            ->first()?->pivot?->cleared_up_to_message_id ?? '');

        $quoted = $conversation->messages()
            ->whereKey($id)
            ->where('id', '>', $cleared)
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('message_user_deletions as d')
                ->whereColumn('d.message_id', 'messages.id')
                ->where('d.user_id', $user->id))
            ->first();

        if (! $quoted) {
            throw ValidationException::withMessages([
                'reply_to_message_id' => 'That message is not part of this conversation.',
            ]);
        }

        return $quoted;
    }

    /**
     * Tell the room, then tell each participant's own line.
     *
     * Two events because they answer different questions. The message goes to
     * whoever has this conversation open, so the bubble lands without a
     * round-trip; the thin signal goes to everyone in it, open or not, so a
     * sidebar badge moves for someone reading a different chat.
     *
     * Only a brand-new message may travel as a payload — see MessageSent.
     *
     * `toOthers()` on the first: the author already has this message from the
     * Inertia response, and re-delivering it would race their own optimistic
     * bubble. It is safe if the socket id never arrives — the client upserts
     * by id — but it is not free, so the client sends the header explicitly.
     *
     * The push is third and deliberately last. `afterResponse()` runs it in
     * this same process once the response has left, so the sender waits for
     * none of it, and — unlike a queued job — nothing has to be running for it
     * to happen. `QUEUE_CONNECTION` is `database` with no worker outside
     * `composer run dev`, so a queued push would be swallowed exactly the way
     * a queued sign-in code would be.
     */
    private function announce(Conversation $conversation, Message $message): void
    {
        broadcast(new MessageSent($message))->toOthers();

        ConversationTouched::dispatch($conversation);

        dispatch(fn () => PushNotifier::messageSent($message))->afterResponse();
    }
}
