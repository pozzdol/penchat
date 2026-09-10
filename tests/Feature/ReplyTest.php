<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function reply(User $sender, Conversation $conversation, string $body, ?string $to)
{
    return test()->actingAs($sender)->post("/conversations/{$conversation->id}/messages", [
        'body' => $body,
        'reply_to_message_id' => $to,
    ]);
}

/**
 * The newest message in the conversation.
 *
 * Not `where('body', ...)`: `body` is encrypted, so no value ever matches in
 * SQL. That is the standing cost of encryption at rest and it catches every
 * test that forgets it.
 */
function newest(Conversation $conversation): Message
{
    return $conversation->messages()->orderByDesc('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| The gate
|--------------------------------------------------------------------------
|
| The client sends an id and the server copies the words. An unchecked id is
| therefore a way to make the server read a message out of a conversation the
| sender cannot see and paste it into one they can. These are the tests that
| matter most in this file.
|
*/

it('refuses to quote a message from someone else conversation', function () {
    [$me, $other, $stranger] = User::factory()->count(3)->create();
    $mine = Conversation::findOrCreateDirect($me, $other);
    $theirs = Conversation::findOrCreateDirect($other, $stranger);
    $secret = say($theirs, $stranger, 'the account number is 1234');

    reply($me, $mine, 'look at this', $secret->id)
        ->assertSessionHasErrors('reply_to_message_id');

    expect($mine->fresh()->messages()->count())->toBe(0);
});

it('refuses to quote a message below my cleared pointer', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $old = say($dm, $other, 'from before');

    test()->actingAs($me)->delete("/conversations/{$dm->id}/history");

    reply($me, $dm, 'about that', $old->id)
        ->assertSessionHasErrors('reply_to_message_id');
});

it('refuses to quote a message I hid from myself', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $hidden = say($dm, $other, 'hidden from me');

    test()->actingAs($me)->delete("/messages/{$hidden->id}/mine");

    reply($me, $dm, 'about that', $hidden->id)
        ->assertSessionHasErrors('reply_to_message_id');
});

it('refuses an id that is not a message at all', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    reply($me, $dm, 'hello', '01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertSessionHasErrors('reply_to_message_id');
});

/*
|--------------------------------------------------------------------------
| Quoting, when it is allowed
|--------------------------------------------------------------------------
*/

it('stores the quoted words and a link back to the original', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $original = say($dm, $other, 'are we still on for after work?');

    reply($me, $dm, 'yes, see you there', $original->id)->assertSessionHasNoErrors();

    $sent = newest($dm);

    expect($sent->body)->toBe('yes, see you there')
        ->and($sent->reply_to_message_id)->toBe($original->id)
        ->and($sent->reply_to_body)->toBe('are we still on for after work?');
});

it('sends the quote to the client with the original author', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $original = say($dm, $other, 'bring the rope');
    reply($me, $dm, 'got it', $original->id);

    test()->actingAs($me)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->has('messages', 2)
        ->where('messages.1.reply_to.id', $original->id)
        ->where('messages.1.reply_to.body', 'bring the rope')
        ->where('messages.1.reply_to.author', $other->name)
        ->where('messages.1.reply_to.deleted', false)
        ->where('messages.0.reply_to', null));
});

/**
 * The whole reason a snapshot was chosen over a live join. If the quote were
 * read from the original, editing it would rewrite the words inside somebody
 * else's message after the fact.
 */
it('freezes the quote against a later edit', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $original = say($dm, $me, 'meet at six');
    reply($other, $dm, 'see you then', $original->id);

    test()->actingAs($me)->patch("/messages/{$original->id}", ['body' => 'meet at nine']);

    $sent = newest($dm);

    expect($sent->reply_to_body)->toBe('meet at six')
        ->and($original->fresh()->body)->toBe('meet at nine');
});

it('keeps the quote when the original becomes a tombstone, and says so', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $original = say($dm, $me, 'forget I said that');
    reply($other, $dm, 'too late', $original->id);

    test()->actingAs($me)->delete("/messages/{$original->id}");

    test()->actingAs($other)->get("/c/{$dm->id}")->assertInertia(fn ($page) => $page
        ->where('messages.1.reply_to.body', 'forget I said that')
        ->where('messages.1.reply_to.deleted', true));
});

it('leaves the quote empty when there was nothing to quote', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $bare = Message::factory()->create([
        'conversation_id' => $dm->id,
        'user_id' => $other->id,
        'body' => null,
    ]);

    reply($me, $dm, 'what is that', $bare->id)->assertSessionHasNoErrors();

    expect(newest($dm)->reply_to_body)->toBeNull();
});

/** Message content, so it is unreadable in a dump for the same reason `body` is. */
it('encrypts the quoted copy at rest', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);
    $original = say($dm, $other, 'the account number is 1234');

    reply($me, $dm, 'noted', $original->id);

    $raw = DB::table('messages')->whereNotNull('reply_to_body')->value('reply_to_body');

    expect($raw)->toBeString()->not->toContain('account number')
        ->and(newest($dm)->reply_to_body)->toBe('the account number is 1234');
});

it('still sends a plain message with no reply at all', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($me, $other);

    reply($me, $dm, 'just talking', null)->assertSessionHasNoErrors();

    $sent = newest($dm);
    expect($sent->reply_to_message_id)->toBeNull()->and($sent->reply_to_body)->toBeNull();
});
