<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FixedLocaleMail extends Mailable
{
    public function __construct()
    {
        $this->locale('de');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Fixed locale');
    }

    public function content(): Content
    {
        return new Content(view: 'fixed-locale');
    }
}
