<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        // The code leads so it is readable from a notification preview.
        return new Envelope(subject: "{$this->code} is your PenChat code");
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Your PenChat sign-in code is <strong>{$this->code}</strong>. It expires in 10 minutes.</p>"
                .'<p>If you did not ask for it, you can ignore this email.</p>',
        );
    }
}
