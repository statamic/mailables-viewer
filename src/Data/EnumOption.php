<?php

namespace Statamic\MailablesViewer\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class EnumOption implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $label,
        public string $value,
        public string|int|null $backingValue,
    ) {}

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'backing_value' => $this->backingValue,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
