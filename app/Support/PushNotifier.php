<?php

namespace App\Support;

use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Who needs to be told, and what it says. Deliberately knows nothing about
 * how a push is signed or encrypted — {@see WebPushSender} owns that, and the
 * seam is there because the two halves have different futures: a native app
 * brings a new transport, but "everyone in the room except the author, and
 * here is the line they should read" is the same sentence forever.
 *
 * Static, like {@see SpamGuard}, and called the same way: one line from the
 * controller, all of the reasoning in here.
 */
class PushNotifier
{
    /**
     * Long enough to tell an "on my way" from a paragraph, short enough that
     * no lock screen truncates it for us.
     */
    private const EXCERPT_CHARS = 120;

    /**
     * Announce a newly created message to everyone else's devices.
     *
     * Only ever called for a *new* message. An edit or a tombstone must not
     * reach here for the same reason neither may travel as a broadcast
     * payload: what a given participant is allowed to see depends on their
     * own `cleared_up_to_message_id` and `message_user_deletions` rows, and a
     * push is one payload for one device with no server left in the loop to
     * filter it. A brand-new message is the one thing no filter can be hiding.
     */
    public static function messageSent(Message $message): void
    {
        $recipients = self::recipients($message);

        if ($recipients === []) {
            return;
        }

        $subscriptions = PushSubscription::whereIn('user_id', $recipients)->get();

        app(WebPushSender::class)->send($subscriptions, self::payload($message));
    }

    /**
     * Everyone in the conversation except the author, minus anyone who has
     * already read this far.
     *
     * That second filter is not an optimisation. The response has already
     * gone out by the time this runs, so a participant reading on another
     * device in that window would otherwise be buzzed about a message they
     * are looking at.
     *
     * Three groups are deliberately *not* excluded:
     *
     * - **Suspended accounts.** A suspension is read-only, not a lockout
     *   (AGENTS.md § Data protection). Silencing their notifications would
     *   punish them with something nobody chose.
     * - **Participants who hid the conversation.** `hidden_at` means "off my
     *   list until something arrives I have not dismissed" — and this is
     *   exactly that something.
     * - **Participants who are online.** The server does not know who is
     *   online; presence is never persisted. The service worker decides,
     *   because only it can see whether a window is focused on this very
     *   conversation.
     *
     * @return list<string>
     */
    private static function recipients(Message $message): array
    {
        return $message->conversation
            ->participants()
            ->whereKeyNot($message->user_id)
            ->where(function ($q) use ($message) {
                $q->whereNull('conversation_user.last_read_message_id')
                    // SQL, not PHP: string comparison here is lexicographic,
                    // which is what ULIDs need. PHP would compare two
                    // all-digit ids numerically and get it wrong.
                    ->orWhere('conversation_user.last_read_message_id', '<', $message->id);
            })
            ->pluck('users.id')
            ->all();
    }

    /**
     * One payload for every device on this message, which is safe precisely
     * because a new message looks the same to every participant.
     *
     * Every key here is read by `public/sw.js`, and every key it reads is
     * here — asserted both ways in RealtimeContractTest, because a payload
     * field nobody reads is dead weight and a field nobody sends is
     * `undefined` on a lock screen. That symmetry is why there is no
     * `message_id`: `tag` already collapses a run of messages from one room,
     * so nothing would ever look at it.
     *
     * @return array<string, mixed>
     */
    private static function payload(Message $message): array
    {
        $conversation = $message->conversation;
        $author = $message->user ?? User::find($message->user_id);
        $name = $author?->name ?? 'Someone';
        $excerpt = self::excerpt($message);

        return [
            'conversation_id' => $conversation->id,
            'url' => '/c/'.$conversation->id,
            // A group is named by the room, because "Andi" tells you nothing
            // about which of five rooms just lit up. A direct chat is named by
            // the person, because there is nothing else it could be.
            'title' => $conversation->isGroup()
                ? ($conversation->name ?: 'Group')
                : $name,
            'body' => $conversation->isGroup()
                ? $name.': '.$excerpt
                : $excerpt,
        ];
    }

    /**
     * A message with no body is an attachment — `body` is nullable and Phase 3
     * fills the gap. Saying so beats a notification that is silently blank.
     */
    private static function excerpt(Message $message): string
    {
        $body = trim((string) $message->body);

        if ($body === '') {
            return 'Sent an attachment';
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $body), self::EXCERPT_CHARS);
    }
}
