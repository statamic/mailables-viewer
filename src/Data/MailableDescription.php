<?php

namespace Statamic\MailablesViewer\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class MailableDescription implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $class,
        public string $name,
        public ?string $path,
        public ?string $subject,
        public ?string $from,
        public int $attachments,
        public bool $queued,
        public ?string $error,
        public Inspection $details,
    ) {}

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'name' => $this->name,
            'path' => $this->path,
            'subject' => $this->subject,
            'from' => $this->from,
            'attachments' => $this->attachments,
            'queued' => $this->queued,
            'error' => $this->error,
            'details' => $this->details->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
