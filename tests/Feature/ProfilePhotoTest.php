<?php

use App\Models\User;
use App\Support\Avatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The first bytes this app accepts from a person
|--------------------------------------------------------------------------
|
| Written against a real disk rather than `Storage::fake`, because half of
| what is being asserted is that GD actually produced a WebP square — a fake
| would happily store whatever it was handed and every one of these would
| pass without the pipeline running at all.
|
*/

beforeEach(function () {
    config(['filesystems.disks.public.root' => storage_path('framework/testing/avatars')]);
    Storage::disk('public')->deleteDirectory('avatars');
});

/** A real image of the given size, as bytes a browser would post. */
function picture(int $width, int $height, string $format = 'png'): UploadedFile
{
    return UploadedFile::fake()->image("whatever.{$format}", $width, $height);
}

it('stores a photo as a square webp and points the user at it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/photo', ['photo' => picture(900, 600)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $path = $user->fresh()->avatar_path;

    expect($path)->toStartWith('avatars/')
        ->and($path)->toEndWith('.webp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();

    // The bytes on disk, not the bytes that were sent.
    $written = imagecreatefromstring(Storage::disk('public')->get($path));

    expect(imagesx($written))->toBe(Avatar::SIZE)
        ->and(imagesy($written))->toBe(Avatar::SIZE);
});

/** The uploader names the file; the app names what it keeps. */
it('never uses the name the uploader chose', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/photo', [
        'photo' => UploadedFile::fake()->image('../../etc/passwd.png', 50, 50),
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->avatar_path)->toMatch('#^avatars/[0-9A-HJKMNP-TV-Z]{26}\.webp$#');
});

/**
 * SVG is a scripting format wearing a picture's name. It is refused by the
 * mime allowlist, and would be refused again by GD, which cannot decode it.
 */
it('refuses an svg', function () {
    $user = User::factory()->create();

    $svg = UploadedFile::fake()->createWithContent(
        'avatar.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($user)
        ->post('/settings/photo', ['photo' => $svg])
        ->assertSessionHasErrors('photo');

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('refuses a file that is not an image at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/photo', [
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', 'not an image'),
        ])
        ->assertSessionHasErrors('photo');

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('refuses a file bigger than php will accept', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/photo', [
            'photo' => UploadedFile::fake()->image('avatar.png', 100, 100)->size(3000),
        ])
        ->assertSessionHasErrors('photo');
});

/** Replacing takes the old file with it, or the disk fills with faces nobody has. */
it('deletes the photo it replaced', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/photo', ['photo' => picture(100, 100)]);
    $first = $user->fresh()->avatar_path;

    $this->actingAs($user)->post('/settings/photo', ['photo' => picture(120, 120)]);
    $second = $user->fresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists($first))->toBeFalse()
        ->and(Storage::disk('public')->exists($second))->toBeTrue();
});

it('removes the photo and its file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/photo', ['photo' => picture(100, 100)]);
    $path = $user->fresh()->avatar_path;

    $this->actingAs($user)->delete('/settings/photo')->assertRedirect();

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('does not mind being asked to remove a photo that is not there', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete('/settings/photo')->assertRedirect();

    expect($user->fresh()->avatar_path)->toBeNull();
});

/** The photo is published to everyone this person talks to, so it has a URL. */
it('hands the url to the people who share a conversation', function () {
    [$me, $other] = User::factory()->count(2)->create();
    acquainted($me, $other);

    $this->actingAs($other)->post('/settings/photo', ['photo' => picture(80, 80)]);

    $props = $this->actingAs($me)->get('/')->viewData('page')['props'];
    $participants = collect($props['conversations'][0]['participants']);

    expect($participants->firstWhere('id', $other->id)['avatar_url'])
        ->toContain($other->fresh()->avatar_path);
});

it('says nothing about a person with no photo', function () {
    [$me, $other] = User::factory()->count(2)->create();
    acquainted($me, $other);

    $props = $this->actingAs($me)->get('/')->viewData('page')['props'];
    $participants = collect($props['conversations'][0]['participants']);

    expect($participants->firstWhere('id', $other->id)['avatar_url'])->toBeNull();
});

/** Your face sits in every sidebar, same as your name. */
it('refuses a suspended account', function () {
    $user = User::factory()->create(['suspended_until' => now()->addDay()]);

    $this->actingAs($user)->post('/settings/photo', ['photo' => picture(100, 100)]);

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('turns a guest away', function () {
    $this->post('/settings/photo', ['photo' => picture(100, 100)])->assertRedirect('/login');
});

afterEach(function () {
    Storage::disk('public')->deleteDirectory('avatars');
});

/**
 * The bug this was found by: PHP-FPM runs as www-data, the avatars directory
 * had been created by a command run as root, and the write failed. The
 * `public` disk is configured `'throw' => false, 'report' => false`, so it
 * failed *silently* — and the row was updated anyway, leaving an avatar that
 * never loaded while Change and Remove insisted one existed.
 *
 * Reproduced by putting a regular *file* where the `avatars` directory needs
 * to go: the disk root is fine so the adapter builds, and then the write
 * cannot create its directory and returns false — the same shape as a
 * directory the web user may not write to. `chmod` would prove nothing here,
 * since the suite runs as root and root ignores the mode bits.
 */
it('refuses rather than recording a photo it could not write', function () {
    $user = User::factory()->create();
    $root = storage_path('framework/testing/blocked-disk');

    @mkdir($root, 0755, true);
    file_put_contents($root.'/avatars', 'a file where a directory has to go');

    try {
        config(['filesystems.disks.public.root' => $root]);
        // The disk was already resolved and cached by `beforeEach`; without
        // this the new root is config nobody reads.
        Storage::forgetDisk('public');

        $this->actingAs($user)
            ->post('/settings/photo', ['photo' => picture(100, 100)])
            ->assertSessionHasErrors('photo');

        // The row is what matters. A path here with nothing behind it is the
        // broken state the whole check exists to prevent.
        expect($user->fresh()->avatar_path)->toBeNull();
    } finally {
        @unlink($root.'/avatars');
        @rmdir($root);
    }
});

/**
 * The browser compresses to its own ceiling before posting, and the server
 * refuses anything past PHP's. Raise the client's above the server's and
 * uploads start failing at a boundary nobody can see — the browser having
 * already decided the file was fine.
 */
it('keeps the browser ceiling at or below what the server accepts', function () {
    preg_match(
        '/MAX_BYTES = (\d+) \* (\d+)/',
        file_get_contents(resource_path('js/lib/image.ts')),
        $client,
    );

    preg_match(
        "/'max:(\d+)'/",
        file_get_contents(app_path('Http/Requests/UpdateProfilePhotoRequest.php')),
        $server,
    );

    $clientBytes = (int) ($client[1] ?? 0) * (int) ($client[2] ?? 0);
    $serverBytes = (int) ($server[1] ?? 0) * 1024;

    expect($clientBytes)->toBeGreaterThan(0)
        ->and($serverBytes)->toBeGreaterThan(0)
        ->and($clientBytes)->toBeLessThanOrEqual($serverBytes);
});

/** And the server's own limit must be one PHP will actually honour. */
it('keeps the server limit inside what php will accept', function () {
    preg_match(
        "/'max:(\d+)'/",
        file_get_contents(app_path('Http/Requests/UpdateProfilePhotoRequest.php')),
        $server,
    );

    $limit = (int) ($server[1] ?? 0) * 1024;
    $php = (int) filter_var(ini_get('upload_max_filesize'), FILTER_SANITIZE_NUMBER_INT) * 1024 * 1024;

    // A validation rule looser than `upload_max_filesize` is a message that
    // never gets to run: PHP drops the body first and the request arrives with
    // nothing in it.
    //
    // Read from the CLI, which is not strictly the SAPI that serves uploads —
    // they happen to carry the same value on this host. A machine whose FPM
    // pool is configured lower would pass here and still refuse in production,
    // so this catches the rule drifting upwards, not a mismatched php.ini.
    expect($limit)->toBeLessThanOrEqual($php);
});
