<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $me = User::factory()->create(['name' => 'Fikri Anshori', 'username' => 'fikri', 'email' => 'fikri@example.com']);
        $luis = User::factory()->create(['name' => 'Luís Kolen', 'username' => 'luis', 'email' => 'luis@example.com']);
        $paul = User::factory()->create(['name' => 'Paul Davies', 'username' => 'paul', 'email' => 'paul@example.com']);
        $mathilda = User::factory()->create(['name' => 'Mathilda Bond', 'username' => 'mathilda', 'email' => 'mathilda@example.com']);
        $peter = User::factory()->create(['name' => 'Peter Swensen', 'username' => 'peter', 'email' => 'peter@example.com']);

        $say = fn (Conversation $c, User $from, string $body, Carbon $at): Message => Message::create([
            'conversation_id' => $c->id,
            'user_id' => $from->id,
            'body' => $body,
            'created_at' => $at,
        ]);

        // Luís has read everything; I have not read his last two.
        $dm = Conversation::findOrCreateDirect($me, $luis);
        $say($dm, $luis, 'Morning — did the Reverb config land?', now()->subDay()->setTime(9, 12));
        $say($dm, $me, 'It did. Both env blocks, server and browser.', now()->subDay()->setTime(9, 14));
        $mine = $say($dm, $me, 'The VITE_ ones need a rebuild to take effect, so I ran one.', now()->subDay()->setTime(9, 14));
        $say($dm, $luis, 'Good catch. That one bites every time.', now()->subDay()->setTime(9, 31));
        $newest = $say($dm, $luis, 'I am still thinking about the read-receipt design though.', now()->subMinutes(42));
        // Ids are ULIDs, so a pointer is always some real message's id — never
        // an id plus an offset.
        $this->read($dm, $luis, $newest->id);
        $this->read($dm, $me, $mine->id);

        $dm = Conversation::findOrCreateDirect($me, $paul);
        $say($dm, $paul, 'Are we still on for after work?', now()->subHours(3)->subMinutes(10));
        $last = $say($dm, $me, 'Thanks, see you in a bar after work.', now()->subHours(3));
        $this->read($dm, $paul, $last->id);
        $this->read($dm, $me, $last->id);

        $dm = Conversation::findOrCreateDirect($me, $mathilda);
        $say($dm, $mathilda, 'Landed safely.', now()->subDay()->setTime(18, 2));
        $say($dm, $mathilda, 'Flat is smaller than the photos, of course.', now()->subDay()->setTime(18, 5));
        $say($dm, $mathilda, 'Take care mom, see you soon.', now()->subDay()->setTime(18, 6));

        $dm = Conversation::findOrCreateDirect($me, $peter);
        $last = $say($dm, $me, 'Big up and take care man!', now()->subDays(2)->setTime(21, 40));
        $this->read($dm, $me, $last->id);

        $group = $this->group('Ridgeline Deploys', $me, [$paul, $mathilda, $peter]);
        $say($group, $paul, 'Rolling staging now, expect a two-minute blip.', now()->subMinutes(31));
        $say($group, $peter, 'Migration is taking longer than the dry run did.', now()->subMinutes(24));
        $say($group, $mathilda, 'Staging is back up — the migration finished.', now()->subMinutes(18));

        $group = $this->group('Weekend Climb', $me, [$luis, $peter]);
        $say($group, $luis, 'Who is in for Saturday?', now()->subDays(9)->setTime(20, 15));
        $say($group, $peter, 'Forecast says rain until Saturday noon.', now()->subDays(9)->setTime(20, 30));
    }

    /** @param  list<User>  $members */
    private function group(string $name, User $creator, array $members): Conversation
    {
        return Conversation::createGroup($name, $creator, $members);
    }

    private function read(Conversation $conversation, User $reader, string $messageId): void
    {
        $conversation->participants()->updateExistingPivot($reader->id, ['last_read_message_id' => $messageId]);
    }
}
