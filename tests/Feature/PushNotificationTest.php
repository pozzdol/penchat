<?php

use App\Models\Conversation;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushNotifier;
use App\Support\WebPushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Minishlink\WebPush\VAPID;

/*
|--------------------------------------------------------------------------
| Who is told, and what it says
|--------------------------------------------------------------------------
|
| Everything here is about PushNotifier, which decides the recipients and
| writes the line they read. The transport is stubbed out: what reaches a
| device is the library's problem, but who it reaches is ours.
|
*/

/**
 * Stands in for the real sender and remembers what it was handed.
 *
 * Bound into the container rather than passed in, because the production
 * call is `app(WebPushSender::class)` from a static — which is exactly the
 * seam that makes a static testable.
 */
function spySender(): WebPushSender
{
    $spy = new class extends WebPushSender
    {
        /** @var list<array{subscriptions: Collection, payload: array}> */
        public array $calls = [];

        public function send(Collection $subscriptions, array $payload): void
        {
            $this->calls[] = ['subscriptions' => $subscriptions, 'payload' => $payload];
        }
    };

    app()->instance(WebPushSender::class, $spy);

    return $spy;
}

/** The endpoints a spy was asked to push to, in no particular order. */
function pushedTo(WebPushSender $spy): array
{
    return collect($spy->calls)
        ->flatMap(fn (array $call) => $call['subscriptions']->pluck('endpoint'))
        ->all();
}

function subscribe(User $user, string $endpoint): PushSubscription
{
    return PushSubscription::factory()->create([
        'user_id' => $user->id,
        'endpoint' => $endpoint,
    ]);
}

it('pushes to every participant except the author', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);

    subscribe($author, 'https://push.example.com/author');
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, 'hello'));

    expect(pushedTo($spy))->toEqual(['https://push.example.com/reader']);
});

it('pushes to every device a participant has', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);

    subscribe($reader, 'https://push.example.com/laptop');
    subscribe($reader, 'https://push.example.com/phone');

    PushNotifier::messageSent(say($dm, $author, 'hello'));

    expect(pushedTo($spy))->toEqualCanonicalizing([
        'https://push.example.com/laptop',
        'https://push.example.com/phone',
    ]);
});

/**
 * The response is already out by the time the push runs, so a reader who
 * caught up on another device in that window must not be buzzed about a
 * message they are looking at.
 */
it('skips a participant who has already read this far', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    $message = say($dm, $author, 'hello');
    $dm->participants()->updateExistingPivot($reader->id, [
        'last_read_message_id' => $message->id,
    ]);

    PushNotifier::messageSent($message);

    expect(pushedTo($spy))->toBeEmpty();
});

/**
 * A suspension is read-only, not a lockout (AGENTS.md § Data protection).
 * Silencing someone's notifications would be a sanction nobody chose, and
 * there is no administrator here to lift it.
 */
it('still pushes to a suspended participant', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $reader->forceFill(['suspended_until' => now()->addDay()])->save();

    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, 'hello'));

    expect(pushedTo($spy))->toEqual(['https://push.example.com/reader']);
});

/** `hidden_at` means "off my list until something arrives" — this is that. */
it('still pushes to a participant who hid the conversation', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    $dm->participants()->updateExistingPivot($reader->id, ['hidden_at' => now()]);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, 'hello'));

    expect(pushedTo($spy))->toEqual(['https://push.example.com/reader']);
});

it('titles a direct chat with the sender and carries the text alone', function () {
    $spy = spySender();

    $author = User::factory()->create(['name' => 'Andi']);
    $reader = User::factory()->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, 'on my way'));

    expect($spy->calls[0]['payload'])->toEqual([
        'conversation_id' => $dm->id,
        'url' => '/c/'.$dm->id,
        'title' => 'Andi',
        'body' => 'on my way',
    ]);
});

/**
 * A group is named by the room. "Andi" alone would not say which of five
 * rooms just lit up, so the sender moves into the body instead.
 */
it('titles a group with the room and names the sender in the body', function () {
    $spy = spySender();

    $author = User::factory()->create(['name' => 'Andi']);
    $reader = User::factory()->create();
    acquainted($author, $reader);
    $room = group('Standup', $author, [$reader]);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($room, $author, 'on my way'));

    expect($spy->calls[0]['payload'])->toMatchArray([
        'title' => 'Standup',
        'body' => 'Andi: on my way',
    ]);
});

it('trims a long message down to an excerpt', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, str_repeat('a', 500)));

    expect(strlen($spy->calls[0]['payload']['body']))->toBeLessThanOrEqual(123)
        ->and($spy->calls[0]['payload']['body'])->toEndWith('...');
});

it('collapses the newlines a pasted message brings with it', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    PushNotifier::messageSent(say($dm, $author, "first\n\nsecond"));

    expect($spy->calls[0]['payload']['body'])->toBe('first second');
});

it('says an attachment arrived when there is no text', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    $message = say($dm, $author, 'placeholder');
    $message->forceFill(['body' => null])->save();

    PushNotifier::messageSent($message->fresh());

    expect($spy->calls[0]['payload']['body'])->toBe('Sent an attachment');
});

/**
 * The whole point of routing this through `afterResponse()` rather than the
 * queue: nothing has to be running for it to happen. If this stops passing,
 * every notification has gone quiet without a single error anywhere.
 */
it('pushes when a message is sent over HTTP', function () {
    $spy = spySender();

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    $this->actingAs($author)
        ->post("/conversations/{$dm->id}/messages", ['body' => 'hello'])
        ->assertRedirect();

    expect(pushedTo($spy))->toEqual(['https://push.example.com/reader']);
});

/** An edit is not a new message, and must never reach a device as a payload. */
it('does not push when a message is edited', function () {
    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');
    $message = say($dm, $author, 'hello');

    $spy = spySender();

    $this->actingAs($author)
        ->patch("/messages/{$message->id}", ['body' => 'hello there'])
        ->assertRedirect();

    expect($spy->calls)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| The transport
|--------------------------------------------------------------------------
*/

/**
 * 410 Gone is the push service saying the browser threw this subscription
 * away. Nothing will ever revive it, and nothing else will ever tell us —
 * so if this row survives, the table grows forever.
 */
it('forgets a subscription the push service reports as gone', function () {
    $keys = VAPID::createVapidKeys();
    config([
        'services.webpush.public_key' => $keys['publicKey'],
        'services.webpush.private_key' => $keys['privateKey'],
        'services.webpush.subject' => 'mailto:test@example.com',
    ]);

    $user = User::factory()->create();
    $dead = subscribe($user, 'https://push.example.com/dead');
    $alive = subscribe($user, 'https://push.example.com/alive');

    $client = new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(410),
            new Response(201),
        ])),
    ]);

    // Explicit order: the mock answers in the order the notifications were
    // queued, so the pairing of row to response has to be stated, not left to
    // whatever the database returns.
    (new WebPushSender($client))->send(
        collect([$dead, $alive]),
        ['title' => 'x', 'body' => 'y', 'url' => '/'],
    );

    expect(PushSubscription::find($dead->id))->toBeNull()
        ->and(PushSubscription::find($alive->id))->not->toBeNull();
})->skip(fn () => ! extension_loaded('openssl'), 'Needs openssl to build a real key pair.');

/** No keys, no signing — and no exception either. */
it('does nothing when no VAPID keys are configured', function () {
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

    $user = User::factory()->create();
    subscribe($user, 'https://push.example.com/one');

    (new WebPushSender)->send(PushSubscription::all(), ['title' => 'x']);

    expect(PushSubscription::count())->toBe(1);
});

/**
 * This ran after the response has already been written, so an exception here
 * cannot reach the sender — but it does reach the error handler, which then
 * tries to set headers on a response that is already out and logs a second,
 * more confusing failure behind the first. That happened for real: every
 * message sent between this code landing and the migration running threw
 * `relation "push_subscriptions" does not exist` and a "headers already
 * sent" trace after it.
 */
it('never lets a failed push escape into the request that is already done', function () {
    app()->instance(WebPushSender::class, new class extends WebPushSender
    {
        public function send(Collection $subscriptions, array $payload): void
        {
            throw new RuntimeException('the push service is on fire');
        }
    });

    [$author, $reader] = User::factory()->count(2)->create();
    $dm = Conversation::findOrCreateDirect($author, $reader);
    subscribe($reader, 'https://push.example.com/reader');

    $this->actingAs($author)
        ->post("/conversations/{$dm->id}/messages", ['body' => 'hello'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The message itself is committed and broadcast before the push is even
    // attempted, so a dead push service must never cost anybody their words.
    expect($dm->messages()->count())->toBe(1);
});
