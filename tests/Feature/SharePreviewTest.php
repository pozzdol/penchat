<?php

/*
|--------------------------------------------------------------------------
| The link preview
|--------------------------------------------------------------------------
|
| Every failure in this layer is silent. A relative og:image, a missing file,
| a declared size that disagrees with the real one — none of them raise
| anything anywhere. The link simply pastes into WhatsApp as a bare URL, and
| the only way to notice is to send one to somebody.
|
*/

/** @return array<string, string> */
function shareTags(): array
{
    // The login screen, because that is the only page a crawler ever reaches:
    // everything else redirects here.
    $html = test()->get('/login')->getContent();

    preg_match_all(
        '/<meta (?:property|name)="((?:og|twitter):[^"]+)" content="([^"]*)"/',
        $html,
        $matches,
        PREG_SET_ORDER,
    );

    return collect($matches)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all();
}

it('describes the page to a crawler', function () {
    $tags = shareTags();

    expect($tags)->toHaveKeys([
        'og:type', 'og:url', 'og:title', 'og:description',
        'og:image', 'og:image:width', 'og:image:height',
        'twitter:card',
    ])->and($tags['og:title'])->not->toBeEmpty()
        ->and($tags['og:description'])->not->toBeEmpty();
});

/** WhatsApp discards a relative og:image without saying so. */
it('gives the image an absolute url', function () {
    expect(shareTags()['og:image'])->toStartWith(rtrim(config('app.url'), '/'))
        ->and(shareTags()['og:image'])->toMatch('#^https?://#');
});

it('points at an image that is actually there', function () {
    $path = public_path(parse_url(shareTags()['og:image'], PHP_URL_PATH));

    expect(file_exists($path))->toBeTrue();
});

/**
 * Declared and real have to agree. Given a correct pair WhatsApp renders the
 * large 1.91:1 card; given a wrong one it falls back to a small square
 * thumbnail cropped from the middle, which is a different design than the one
 * that was drawn.
 */
it('declares the size the image really is', function () {
    $tags = shareTags();
    [$width, $height] = getimagesize(public_path(parse_url($tags['og:image'], PHP_URL_PATH)));

    expect((int) $tags['og:image:width'])->toBe($width)
        ->and((int) $tags['og:image:height'])->toBe($height)
        // 1.91:1, the ratio every crawler in this list is built around.
        ->and(round($width / $height, 2))->toBe(1.9);
});

/**
 * WhatsApp stops fetching somewhere near 600KB and shows nothing at all
 * rather than a partial picture. Flat artwork lands nowhere near it — this
 * catches somebody dropping a photograph in later.
 */
it('keeps the image small enough to be fetched', function () {
    $bytes = filesize(public_path(parse_url(shareTags()['og:image'], PHP_URL_PATH)));

    expect($bytes)->toBeLessThan(300 * 1024);
});
