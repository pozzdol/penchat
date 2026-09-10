<?php

use App\Mail\LoginCodeMail;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| The sign-in email itself
|--------------------------------------------------------------------------
|
| Its own file, and deliberately without `Mail::fake()`. A fake intercepts the
| mailable before it is ever turned into a message, which is exactly the part
| these tests are about — nothing here can be checked from the object alone.
|
*/

/**
 * The message as it actually goes out, headers and all, decoded.
 *
 * The decode is not cosmetic. Quoted-printable wraps at 76 characters with
 * soft breaks, so a raw search for `cid:` misses it whenever it lands as
 * `c=\r\nid:` — the assertion fails while the email is perfectly fine, or
 * worse, passes for the wrong reason. Decoding asserts what the client reads.
 */
function sentMessage(string $code = '482913'): string
{
    Mail::mailer('array')->to('someone@example.test')->send(new LoginCodeMail($code));

    $raw = Mail::mailer('array')->getSymfonyTransport()->messages()[0]->getMessage()->toString();

    return quoted_printable_decode($raw);
}

/**
 * The template is the one piece of this flow nothing else touches — a broken
 * Blade tag or a renamed view is invisible until somebody cannot sign in.
 */
it('renders the code into both the html and the text part', function () {
    $mail = new LoginCodeMail('482913');

    $html = $mail->render();
    $text = view('mail.login-code-text', ['code' => '482913'])->render();

    expect($mail->envelope()->subject)->toBe('482913 is your PenChat code')
        ->and($html)->toContain('482913')
        ->and($text)->toContain('482913');
});

/**
 * Gmail and Outlook invert colours for dark mode by guessing, and a guess
 * applied to a dark backdrop holding a light card looks worse than either.
 */
it('opts out of the client guessing at dark mode', function () {
    $html = (new LoginCodeMail('482913'))->render();

    expect($html)
        ->toContain('color-scheme: light only')
        ->toContain('name="supported-color-schemes"');
});

/**
 * The mark has to travel *inside* the message. A remote `<img src="https://…">`
 * is blocked by default in most clients, needs a public URL this app does not
 * have yet, and would leak an open signal back to whoever hosts it.
 *
 * Asserted against a real send rather than `render()`, because `render()`
 * inlines a data URI and would pass while the sent message did something else
 * entirely — the failure this is here to catch.
 */
it('carries its logo inside the message rather than fetching it', function () {
    $raw = sentMessage();

    expect($raw)
        ->toContain('multipart/related')
        ->toContain('Content-Disposition: inline')
        ->toContain('image/png')
        // The body points at the attached part, never off to a web server.
        ->toContain('src="cid:')
        ->not->toMatch('/<img[^>]+src="http/');
});

/**
 * The logo's disc is painted the same colour as the email's backdrop so that
 * it vanishes against it and only shows where it crosses the white card. That
 * makes one colour live in two files, and a comment is a poor way to keep
 * them together — drift would leave the mark sitting on a visible patch.
 */
it('paints the logo disc the same colour as the backdrop', function () {
    $svg = file_get_contents(resource_path('images/penchat-mark.svg'));
    $html = (new LoginCodeMail('482913'))->render();

    preg_match('/<body[^>]*background-color:\s*(#[0-9a-f]{6})/i', $html, $backdrop);
    preg_match('/<circle[^>]*fill="(#[0-9a-f]{6})"/i', $svg, $disc);

    expect($backdrop[1] ?? 'no backdrop')->toBe($disc[1] ?? 'no disc');
});

/** The bitmap is generated; the SVG beside it is what anyone should edit. */
it('keeps the source the png was generated from', function () {
    expect(resource_path('images/penchat-mark.svg'))->toBeFile()
        ->and(resource_path('images/penchat-mark.png'))->toBeFile();
});

/**
 * A hidden preheader is ordinary; padding it with repeated zero-width
 * characters is not. Gmail scans for hidden text, and that padding is what
 * tipped an otherwise correctly authenticated message into spam.
 */
it('hides no obfuscated filler in the preheader', function () {
    $html = (new LoginCodeMail('482913'))->render();

    expect($html)
        ->not->toContain('&zwnj;')
        ->not->toContain('&#847;')
        ->not->toContain("\u{200c}")
        ->not->toContain("\u{034f}");
});
