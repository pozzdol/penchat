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

    /**
     * A text part as well as the HTML one. Some people read mail as text, and
     * a message with no text alternative scores worse with spam filters — for
     * an email whose whole job is to arrive, that is not a detail.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.login-code',
            text: 'mail.login-code-text',
        );
    }
}
