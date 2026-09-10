{{--
    The sign-in code email.

    Written the way email has to be written, not the way the app is: tables for
    layout, every style inline, and hex values rather than the OKLCH tokens —
    no mail client resolves a CSS variable. The hex below is converted from
    `tokens.css`, so this is the same palette, not an approximation of it.

    `color-scheme: light only` is deliberate. Gmail and Outlook invert colours
    for dark mode by guessing, and a guess applied to a dark backdrop holding a
    light card produces something worse than either. Opting out keeps the one
    design that was actually checked.

    **The mark is embedded, not linked.** `$message->embed()` builds a
    `multipart/related` part with `Content-Disposition: inline`, so it arrives
    inside the message: it shows without the recipient clicking "display
    images", it survives having no public URL to point at, and it does not
    appear as an attachment. A remote `<img src="https://…">` would fail all
    three. It costs about 5 KB.

    A PNG, because no mail client renders SVG. It is generated from
    `resources/images/penchat-mark.svg`, which sets the mark from
    `public/image/logo/logo.svg` on a round plate — regenerate the PNG from
    that SVG rather than editing the bitmap.

    The plate is the point, not decoration. The mark is a single light colour,
    so the half of it hanging over the white card would simply vanish without
    something behind it. A disc carries it across both grounds at once.
    `alt="PenChat"` covers clients that block even an embedded image.
--}}
<!DOCTYPE html>
<html lang="en" style="color-scheme: light only;">
<head>
    <meta charset="utf-8">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Stops iOS turning the code into a phone number and linking it. --}}
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <!--[if mso]>
    <style>
        td, th, div, p, a, h1 {
            font-family: "Segoe UI", sans-serif;
            mso-line-height-rule: exactly;
        }
    </style>
    <![endif]-->
    <title>{{ $code }} is your PenChat code</title>
    <style>
        :root { color-scheme: light only; }
        @media (max-width: 600px) {
            .sm-px-4 { padding-left: 16px !important; padding-right: 16px !important; }
            .sm-px-6 { padding-left: 24px !important; padding-right: 24px !important; }
            .sm-mt-8 { margin-top: 32px !important; }
        }
    </style>
</head>
<body style="background-color: #22252b; margin: 0; width: 100%; padding: 0; -webkit-font-smoothing: antialiased; word-break: break-word;">
    {{--
        The inbox preview line, beside the subject.

        The usual trick is to pad this with repeated zero-width characters so
        the body text cannot bleed into the preview. Not here: a hidden block
        stuffed with invisible characters is a textbook obfuscation pattern,
        Gmail scans for exactly that, and the padding is what tips an
        authenticated transactional email into spam. Losing it costs nothing —
        the body begins with "Your PenChat sign-in code", which reads perfectly
        well as the rest of a preview.
    --}}
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        Expires in 10 minutes. If you did not ask to sign in, ignore this.
    </div>

    <div role="article" aria-roledescription="email" aria-label="Your PenChat sign-in code" lang="en">
        <div class="sm-px-4" style="background-color: #22252b; font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;">
            <table align="center" cellpadding="0" cellspacing="0" role="none">
                <tr>
                    <td style="width: 552px; max-width: 100%; padding-left: 8px; padding-right: 8px;">

                        {{-- The mark straddles the card's top edge. Outlook's Word
                             engine ignores `position: absolute` and simply stacks it
                             above the card instead — a plainer arrangement, not a
                             broken one, which is the right way for this to fail. --}}
                        <div class="sm-mt-8" style="position: relative; margin-top: 48px; height: 40px; text-align: center;">
                            <img src="{{ $message->embed(resource_path('images/penchat-mark.png')) }}"
                                 width="80" height="80" alt="PenChat"
                                 style="max-width: 100%; vertical-align: middle; line-height: 1; position: absolute; left: 50%; bottom: -40px; transform: translateX(-50%);">
                        </div>

                        <table style="width: 100%;" cellpadding="0" cellspacing="0" role="none">
                            <tr>
                                <td class="sm-px-6" style="border-radius: 16px; padding: 48px; padding-bottom: 32px; font-size: 16px; color: #62676f; background-color: #fdfdff;">

                                    <h1 style="margin: 12px 0 24px; font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 24px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.01em; color: #353a40;">
                                        Your PenChat sign-in code
                                    </h1>

                                    <p style="margin: 0; font-size: 18px; line-height: 26px; color: #62676f;">
                                        Enter this code to finish signing in.
                                    </p>

                                    <br>

                                    {{-- Monospace and letter-spaced: six digits get copied by
                                         eye, and a 1 that looks like a 7 costs someone a retry
                                         they may not get before the code expires. --}}
                                    <p style="display: inline-block; background-color: #22252b; color: #f4f6f8; font-family: ui-monospace, 'SF Mono', SFMono-Regular, Menlo, Consolas, monospace; font-size: 22px; font-weight: 700; line-height: 120%; letter-spacing: 6px; margin: 0; text-decoration: none; padding: 14px 22px 14px 28px; mso-padding-alt: 0px; border-radius: 8px;">
                                        {{ $code }}
                                    </p>

                                    <br>
                                    <br>

                                    <p style="margin: 0; font-size: 15px; line-height: 24px; color: #62676f;">
                                        It expires in <strong style="color: #4e535a;">10 minutes</strong>, and it only works once.
                                    </p>

                                    <div style="height: 1px; background-color: #d2d4d8; margin: 28px 0;"></div>

                                    <p style="margin: 0; font-size: 15px; line-height: 24px; color: #62676f;">
                                        If you did not ask to sign in, nothing has happened to your
                                        account and you can ignore this email.
                                    </p>

                                    <br>

                                    <p style="margin: 0; font-size: 15px; line-height: 24px; color: #62676f;">
                                        Thanks,<br>PenChat
                                    </p>

                                </td>
                            </tr>
                        </table>

                        <p style="margin: 24px 0 48px; text-align: center; font-size: 13px; line-height: 20px; color: #82868c;">
                            Sent because this address was entered on the PenChat sign-in screen.
                        </p>

                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
