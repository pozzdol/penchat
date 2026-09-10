<?php

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/*
|--------------------------------------------------------------------------
| Guards against silent no-ops
|--------------------------------------------------------------------------
|
| Every realtime bug this codebase has hit had the same shape: code that
| compiled, connected, and then quietly did nothing. A cast that made an
| authorization check always true. A broadcaster that answered 200 without
| reading the rules. `->toOthers()` with no trait behind it. A listener on an
| event name that is never emitted.
|
| None of those fail loudly, and none of them are caught by a test that only
| asserts the happy path. These are the tripwires.
|
*/

/** @return list<string> */
function eventClasses(): array
{
    return collect(glob(app_path('Events/*.php')))
        ->map(fn (string $f) => 'App\\Events\\'.basename($f, '.php'))
        ->filter(fn (string $c) => class_exists($c))
        ->values()
        ->all();
}

it('finds the events it is meant to be guarding', function () {
    // Without this, every test below passes by iterating an empty list.
    expect(eventClasses())->not->toBeEmpty();
});

/**
 * `(int) '01m24h96p03yag6b6zh9tm1h7a'` is 1. So is every other ULID. The
 * scaffolding's `(int) $user->id === (int) $id` therefore authorized every
 * user for every other user's channel — correct for auto-increment keys,
 * catastrophic here.
 */
it('never casts an id to a number where authorization depends on it', function () {
    $files = array_merge(
        [base_path('routes/channels.php')],
        glob(app_path('Policies/*.php')),
    );

    foreach ($files as $file) {
        // Comments are stripped first: the rule is explained in a docblock in
        // `channels.php`, and the explanation must not trip its own test.
        $code = implode(' ', array_map(
            fn (array|string $t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all(file_get_contents($file)),
        ));

        $this->assertDoesNotMatchRegularExpression('/\(int\)\s*\$/', $code, basename($file).' casts an id to a number');
        $this->assertStringNotContainsString('intval(', $code, basename($file).' casts an id to a number');
    }
});

/**
 * `broadcast(...)->toOthers()` calls `dontBroadcastToCurrentUser()` only if the
 * event has it. Without `InteractsWithSockets` the call is not an error — it
 * simply does nothing, and the sender receives their own message back.
 */
it('gives every broadcast event the trait that makes toOthers work', function () {
    foreach (eventClasses() as $class) {
        $implements = class_implements($class);

        if (! isset($implements[ShouldBroadcast::class]) && ! isset($implements[ShouldBroadcastNow::class])) {
            continue;
        }

        $this->assertContains(
            InteractsWithSockets::class,
            class_uses_recursive($class),
            "{$class} is missing InteractsWithSockets, so ->toOthers() on it would do nothing",
        );
    }
});

/** A queued broadcast with no worker running disappears without a trace. */
it('broadcasts now rather than through a queue nobody is running', function () {
    foreach (eventClasses() as $class) {
        $this->assertContains(
            ShouldBroadcastNow::class,
            array_keys(class_implements($class)),
            "{$class} should broadcast now; queued, it would vanish with no worker running",
        );
    }
});

/**
 * The cross-language one, and the most valuable. A listener subscribed to an
 * event name the server never emits is invisible: it compiles, it connects,
 * and it waits forever. Renaming on either side breaks this test instead of
 * the app.
 */
it('subscribes the client to names the server actually emits', function () {
    $client = file_get_contents(resource_path('js/hooks/use-realtime.ts'));

    preg_match_all("/'\.([a-z0-9.\-]+)'/", $client, $matches);
    $listened = array_unique($matches[1]);

    expect($listened)->not->toBeEmpty();

    $emitted = collect(eventClasses())
        ->filter(fn (string $c) => method_exists($c, 'broadcastAs'))
        ->map(fn (string $c) => (new ReflectionClass($c))->newInstanceWithoutConstructor()->broadcastAs())
        ->all();

    foreach ($listened as $name) {
        // A whisper is never emitted by the server — it is relayed between
        // clients, and Echo prefixes it. Both facts are easy to forget, which
        // is why the prefix is asserted rather than assumed.
        if (str_starts_with($name, 'client-')) {
            continue;
        }

        $this->assertContains($name, $emitted, "the client listens for '.{$name}' but no event broadcasts as it");
    }
});

/** The other half: an event nobody listens for is dead weight on the wire. */
it('emits nothing the client ignores', function () {
    $client = file_get_contents(resource_path('js/hooks/use-realtime.ts'));

    foreach (eventClasses() as $class) {
        if (! method_exists($class, 'broadcastAs')) {
            continue;
        }

        $name = (new ReflectionClass($class))->newInstanceWithoutConstructor()->broadcastAs();

        $this->assertStringContainsString("'.{$name}'", $client, "nothing listens for '{$name}' from {$class}");
    }
});

/**
 * Typing is whispered, and a whisper travels as `client-<name>`. Listening for
 * the bare name compiles, connects, and never fires.
 */
it('listens for the whisper under its prefixed name', function () {
    $client = file_get_contents(resource_path('js/hooks/use-realtime.ts'));

    expect($client)
        ->toContain("'.client-typing'")
        ->and($client)->toContain("whisper('typing'");
});
