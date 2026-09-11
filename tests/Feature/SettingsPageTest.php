<?php

use App\Models\User;

it('opens settings as the third pane of the chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The same component, not a page of its own — that is what keeps
            // the sidebar, the mobile one-pane rule and the subscriptions.
            ->component('chat')
            ->where('settings_open', true)
            ->where('active_conversation_id', null)
            ->where('account.email', $user->email));
});

/** Nothing else says `settings_open`, so the chat page has to say it is not. */
it('leaves settings closed on every other page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('settings_open', false));
});

/**
 * An email address belongs to the viewer alone. `ParticipantResource` is what
 * describes a person to everyone sharing a room with them, and it must never
 * learn to carry one.
 */
it('never puts an email address in a participant', function () {
    [$me, $other] = User::factory()->count(2)->create();
    acquainted($me, $other);

    $response = $this->actingAs($me)->get('/settings');

    $props = $response->viewData('page')['props'];

    expect(json_encode($props['conversations']))->not->toContain($other->email)
        ->and(json_encode($props['current_user']))->not->toContain($me->email);
});

/** Reading your own settings is not putting anything in front of anyone. */
it('lets a suspended account open settings', function () {
    $user = User::factory()->create(['suspended_until' => now()->addDay()]);

    $this->actingAs($user)->get('/settings')->assertOk();
});

it('turns a guest away', function () {
    $this->get('/settings')->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| The theme, which has no server state to test
|--------------------------------------------------------------------------
|
| All of it lives in the browser, so the only thing that can be guarded here
| is the pair of agreements it rests on. Both fail silently: a chosen theme
| that does not stick, and a palette that drifts from its copy.
|
*/

/**
 * The inline script in the layout cannot import the hook, so the key is
 * written twice. If the two ever disagree the script reads nothing, the page
 * paints the system theme, and React corrects it a frame later — a flash on
 * every load and a choice that appears not to save.
 */
it('resolves the theme from the same storage key the client writes', function () {
    preg_match(
        "/localStorage\.getItem\('([^']+)'\)/",
        file_get_contents(resource_path('views/app.blade.php')),
        $blade,
    );

    preg_match(
        "/THEME_STORAGE_KEY = '([^']+)'/",
        file_get_contents(resource_path('js/hooks/use-theme.ts')),
        $client,
    );

    expect($blade[1] ?? 'a')->toBe($client[1] ?? 'b');
});

/**
 * The dark palette is 25 hand-measured tokens. It used to exist twice — once
 * as `.dark`, once inside a `prefers-color-scheme` media query so the system
 * preference worked without JavaScript — and two copies of a contrast table
 * drift in exactly the way nobody notices until a reader complains.
 */
it('defines the dark palette exactly once', function () {
    // Comments stripped first: the note left in app.css explains the removal
    // and names the rule it removed, and must not fail its own test — the
    // same trick RealtimeContractTest uses on `channels.php`.
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));
    $tokens = file_get_contents(base_path('tokens.css'));

    expect($css)->not->toContain('@media (prefers-color-scheme')
        ->and(substr_count($tokens, "\n.dark {"))->toBe(1);
});

/** Without the script the class is never stamped and the theme never applies. */
it('stamps the theme on the document before the first paint', function () {
    $blade = file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)
        ->toContain('classList.add')
        // Inline and in the head. A module or an effect runs after the
        // browser has already painted the wrong one.
        ->and($blade)->not->toContain('<script defer')
        // One theme-color tag, owned by the script. The media-query pair
        // that used to sit here contradicted anyone who chose a theme.
        // Counting the tag, not the name: the script reaches for the same
        // string to keep it in step.
        ->and(substr_count($blade, '<meta name="theme-color"'))->toBe(1);
});
