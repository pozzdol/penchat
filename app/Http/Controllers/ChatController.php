<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UI-only for now. The four tables in AGENTS.md do not exist yet, so this
 * returns a fixture shaped exactly like the eventual `broadcastWith()` payloads
 * (see resources/js/types/index.ts). Swapping it for real queries should not
 * require touching a single component.
 *
 * Nothing here is persisted, broadcast, or authorized — that is the next
 * change, and it needs the migrations first.
 */
class ChatController extends Controller
{
    public function index(): Response
    {
        $me = $this->participant(1, 'Fikri Anshori', true);

        $luis = $this->participant(2, 'Luís Kolen', true);
        $paul = $this->participant(3, 'Paul Davies', false);
        $mathilda = $this->participant(4, 'Mathilda Bond', true);
        $peter = $this->participant(5, 'Peter Swensen', false);

        $messages = $this->thread();

        return Inertia::render('chat', [
            'current_user' => $me,
            'active_conversation_id' => 1,
            'messages' => $messages,
            'conversations' => [
                [
                    'id' => 1,
                    'type' => 'direct',
                    'name' => null,
                    'participants' => [$me, $luis],
                    'last_message' => $messages[count($messages) - 1],
                    'unread_count' => 0,
                    'mentioned' => false,
                ],
                [
                    'id' => 2,
                    'type' => 'group',
                    'name' => 'Ridgeline Deploys',
                    'participants' => [$me, $paul, $mathilda, $peter],
                    'last_message' => $this->message(
                        id: 91,
                        conversationId: 2,
                        userId: 4,
                        body: 'Staging is back up — the migration finished.',
                        at: Carbon::now()->subMinutes(18),
                    ),
                    'unread_count' => 21,
                    'mentioned' => true,
                ],
                [
                    'id' => 3,
                    'type' => 'direct',
                    'name' => null,
                    'participants' => [$me, $paul],
                    'last_message' => $this->message(
                        id: 92,
                        conversationId: 3,
                        userId: 1,
                        body: 'Thanks, see you in a bar after work.',
                        at: Carbon::now()->subHours(3),
                        delivery: 'read',
                    ),
                    'unread_count' => 0,
                    'mentioned' => false,
                ],
                [
                    'id' => 4,
                    'type' => 'direct',
                    'name' => null,
                    'participants' => [$me, $mathilda],
                    'last_message' => $this->message(
                        id: 93,
                        conversationId: 4,
                        userId: 4,
                        body: 'Take care mom, see you soon.',
                        at: Carbon::now()->subDay(),
                    ),
                    'unread_count' => 3,
                    'mentioned' => false,
                ],
                [
                    'id' => 5,
                    'type' => 'direct',
                    'name' => null,
                    'participants' => [$me, $peter],
                    'last_message' => $this->message(
                        id: 94,
                        conversationId: 5,
                        userId: 1,
                        body: 'Big up and take care man!',
                        at: Carbon::now()->subDays(2),
                        delivery: 'sent',
                    ),
                    'unread_count' => 0,
                    'mentioned' => false,
                ],
                [
                    'id' => 6,
                    'type' => 'group',
                    'name' => 'Weekend Climb',
                    'participants' => [$me, $luis, $peter],
                    'last_message' => $this->message(
                        id: 95,
                        conversationId: 6,
                        userId: 5,
                        body: 'Forecast says rain until Saturday noon.',
                        at: Carbon::now()->subDays(9),
                    ),
                    'unread_count' => 0,
                    'mentioned' => false,
                ],
            ],
        ]);
    }

    /**
     * One conversation with every delivery state visible at once, so the
     * receipt marks can be eyeballed without faking a network.
     *
     * @return list<array<string, mixed>>
     */
    private function thread(): array
    {
        return [
            $this->message(1, 1, 2, 'Morning — did the Reverb config land?', Carbon::yesterday()->setTime(9, 12)),
            $this->message(2, 1, 1, 'It did. Both env blocks, server and browser.', Carbon::yesterday()->setTime(9, 14), 'read'),
            $this->message(3, 1, 1, 'The VITE_ ones need a rebuild to take effect, so I ran one.', Carbon::yesterday()->setTime(9, 14), 'read'),
            $this->message(4, 1, 2, 'Good catch. That one bites every time.', Carbon::yesterday()->setTime(9, 31)),
            $this->message(5, 1, 2, 'I am still thinking about the read-receipt design though.', Carbon::now()->setTime(18, 44)),
            $this->message(6, 1, 1, 'Pointer, not a join table. One column on the pivot.', Carbon::now()->setTime(18, 47), 'read'),
            $this->message(7, 1, 1, 'This one has not left the client yet.', Carbon::now()->setTime(18, 49), 'pending'),
            $this->message(8, 1, 1, 'And this one could not be delivered.', Carbon::now()->setTime(18, 49), 'failed'),
        ];
    }

    /** @return array<string, mixed> */
    private function participant(int $id, string $name, bool $online): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'avatar_url' => null,
            'online' => $online,
        ];
    }

    /** @return array<string, mixed> */
    private function message(
        int $id,
        int $conversationId,
        int $userId,
        string $body,
        Carbon $at,
        string $delivery = 'sent',
    ): array {
        return [
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => $at->toIso8601String(),
            'attachments' => [],
            'delivery' => $delivery,
        ];
    }
}
