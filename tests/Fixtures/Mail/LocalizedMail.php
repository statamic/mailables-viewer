<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Facades\Site;

class LocalizedMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Subject in '.app()->getLocale());
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Site '.Site::current()->handle().' in '.app()->getLocale().'</p>');
    }
}
