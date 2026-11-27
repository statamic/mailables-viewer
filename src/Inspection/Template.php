<?php

namespace Statamic\MailablesViewer\Inspection;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Template implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?string $engine = null,
        public ?string $view = null,
        public ?string $path = null,
        public ?string $textView = null,
    ) {}

    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'view' => $this->view,
            'path' => $this->path,
            'text_view' => $this->textView,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
