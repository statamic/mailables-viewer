<?php

namespace Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Statamic\MailablesViewer\Contracts\HasPreviewExamples;

class ExampleMail extends Mailable implements HasPreviewExamples
{
    public function __construct(
        public string $name,
        public string $email,
        public int $count = 1,
        public array $items = [],
        public ?Collection $labels = null,
        public ?object $plain = null,
    ) {}

    public static function previewExamples(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'count' => 3,
            'items' => [['sku' => 'A-1', 'quantity' => 2]],
            'labels' => collect(['new', 'sale']),
            'plain' => new \stdClass,
            'unknown' => 'ignored',
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order for '.$this->name);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.$this->name.' ordered '.count($this->items).' item(s).</p>');
    }
}
