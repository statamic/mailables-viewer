<?php

namespace Tests\Fixtures\Mail;

use DateTimeInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ScalarMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $email,
        public string $url,
        public int $count,
        public bool $active,
        public array $items,
        public DateTimeInterface $when,
        public string $optional = 'default-value',
        public ?string $nullable = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Hello '.$this->name);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello '.$this->name.'</p>');
    }
}
