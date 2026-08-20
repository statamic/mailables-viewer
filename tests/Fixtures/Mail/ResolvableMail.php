<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResolvableMail extends Mailable
{
    public function __construct(public Greeting $greeting) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->greeting->message());
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.$this->greeting->message().'</p>');
    }
}
