<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome!',
            from: new Address('hello@example.com', 'Acme'),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Welcome to the list.</p>');
    }
}
