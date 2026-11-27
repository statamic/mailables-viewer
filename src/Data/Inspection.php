<?php

namespace Statamic\MailablesViewer\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Inspection implements Arrayable, JsonSerializable
{
    /**
     * @param  ConstructorParameter[]  $constructor
     * @param  EventListener[]  $events
     * @param  Reference[]  $references
     */
    public function __construct(
        public bool $queued,
        public ?string $queue,
        public Template $template,
        public array $constructor,
        public array $events,
        public array $references,
    ) {}

    public function toArray(): array
    {
        return [
            'queued' => $this->queued,
            'queue' => $this->queue,
            'template' => $this->template->toArray(),
            'constructor' => array_map(fn (ConstructorParameter $parameter) => $parameter->toArray(), $this->constructor),
            'events' => array_map(fn (EventListener $event) => $event->toArray(), $this->events),
            'references' => array_map(fn (Reference $reference) => $reference->toArray(), $this->references),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
