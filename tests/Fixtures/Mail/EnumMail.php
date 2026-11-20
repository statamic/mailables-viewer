<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Tests\Fixtures\Enums\Channel;
use Tests\Fixtures\Enums\Tone;

class EnumMail extends Mailable
{
    public function __construct(
        public Tone $tone,
        public Channel $channel = Channel::Post,
        public ?Tone $reply = null,
        public ?Channel $copy = Channel::Email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A '.$this->tone->value.' hello');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.$this->tone->name.' by '.$this->channel->name.', copy by '.($this->copy->name ?? 'nothing').'</p>');
    }
}
