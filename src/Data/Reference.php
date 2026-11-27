<?php

namespace Statamic\MailablesViewer\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class Reference implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $file,
        public string $kind,
    ) {}

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'kind' => $this->kind,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
