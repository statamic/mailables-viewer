<?php

namespace Statamic\MailablesViewer\Inspection;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class ConstructorParameter implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $type,
        public mixed $value,
        public ?string $input,
    ) {}

    public function editable(): bool
    {
        return $this->input !== null;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'value' => $this->value,
            'input' => $this->input,
            'editable' => $this->editable(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
