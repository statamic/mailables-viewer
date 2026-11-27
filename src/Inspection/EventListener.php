<?php

namespace Statamic\MailablesViewer\Inspection;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class EventListener implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $event,
        public string $listener,
    ) {}

    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'listener' => $this->listener,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
