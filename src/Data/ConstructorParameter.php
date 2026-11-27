<?php

namespace Statamic\MailablesViewer\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

readonly class ConstructorParameter implements Arrayable, JsonSerializable
{
    /**
     * @param  EnumOption[]|null  $options
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $type,
        public mixed $value,
        public ?string $input,
        public ?array $options,
        public bool $nullable,
        public bool $example,
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
            'options' => $this->options === null
                ? null
                : array_map(fn (EnumOption $option) => $option->toArray(), $this->options),
            'nullable' => $this->nullable,
            'example' => $this->example,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
