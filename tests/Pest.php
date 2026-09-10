<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Shared by the feature tests. They live here rather than at the top of one
| test file because a second file declaring the same function is a fatal
| redeclare, not a warning.
|
*/

function say(Conversation $c, User $from, string $body): Message
{
    return Message::factory()->create([
        'conversation_id' => $c->id,
        'user_id' => $from->id,
        'body' => $body,
    ]);
}

/**
 * Give two people a history, so one may add the other to a group.
 *
 * Groups can only be built out of people you have already spoken to, so a
 * test that adds a stranger is now testing the refusal. Where that is not
 * the point of the test, this is how a real user would have got there.
 */
function acquainted(User $a, User $b): Conversation
{
    return Conversation::findOrCreateDirect($a, $b);
}

/** A group with the creator as owner and admin, everyone else a plain member. */
function group(string $name, User $owner, array $members = []): Conversation
{
    return Conversation::createGroup($name, $owner, $members)->load('participants');
}
