<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Statamic\Contracts\Auth\User;

class UserMail extends Mailable
{
    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Hello '.$this->user->email());
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello '.$this->user->email().'</p>');
    }
}
