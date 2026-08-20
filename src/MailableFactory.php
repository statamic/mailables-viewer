<?php

namespace Statamic\MailablesViewer;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\User;

class MailableFactory
{
    public function make(string $class, array $overrides = []): Mailable
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (! $constructor || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $args = collect($constructor->getParameters())
            ->map(fn (ReflectionParameter $parameter) => $this->resolveParameter($parameter, $overrides))
            ->all();

        return $reflection->newInstanceArgs($args);
    }

    protected function resolveParameter(ReflectionParameter $parameter, array $overrides = []): mixed
    {
        if (array_key_exists($parameter->getName(), $overrides) && $this->isOverridable($parameter)) {
            return $this->castOverride($parameter, $overrides[$parameter->getName()]);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if (! $unionType instanceof ReflectionNamedType || $unionType->getName() === 'null') {
                    continue;
                }

                try {
                    return $this->resolveType($unionType->getName(), $parameter);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->resolveType($type->getName(), $parameter);
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        if ($parameter->isVariadic()) {
            return [];
        }

        return $this->fakeScalar('string', $parameter->getName());
    }

    protected function resolveType(string $type, ReflectionParameter $parameter): mixed
    {
        if ($type === 'null') {
            return null;
        }

        if (in_array($type, ['int', 'integer', 'float', 'double', 'bool', 'boolean', 'string', 'array'])) {
            return $this->fakeScalar($type, $parameter->getName());
        }

        if (enum_exists($type)) {
            $cases = $type::cases();

            if (! empty($cases)) {
                return $cases[0];
            }
        }

        if (is_a($type, DateTimeInterface::class, true)) {
            return now();
        }

        if (is_a($type, EntryContract::class, true)) {
            return Entry::query()->first();
        }

        if (is_a($type, UserContract::class, true)) {
            return User::query()->first() ?? User::current();
        }

        if (is_a($type, FormContract::class, true)) {
            return Form::all()->first();
        }

        if (is_a($type, SubmissionContract::class, true)) {
            return FormSubmission::query()->first();
        }

        if (is_a($type, Model::class, true)) {
            return $this->resolveEloquentModel($type);
        }

        try {
            return app()->make($type);
        } catch (\Throwable $e) {
            if ($parameter->allowsNull()) {
                return null;
            }

            throw $e;
        }
    }

    protected function resolveEloquentModel(string $class): mixed
    {
        if (method_exists($class, 'factory')) {
            try {
                return $class::factory()->make();
            } catch (\Throwable) {
                //
            }
        }

        try {
            if ($model = $class::query()->first()) {
                return $model;
            }
        } catch (\Throwable) {
            //
        }

        return $class::make();
    }

    protected function fakeScalar(string $type, string $name): mixed
    {
        return match ($type) {
            'int', 'integer' => 42,
            'float', 'double' => 42.0,
            'bool', 'boolean' => true,
            'array' => [],
            default => $this->fakeString($name),
        };
    }

    protected function fakeString(string $name): string
    {
        $name = Str::lower($name);

        return match (true) {
            str_contains($name, 'email') => 'preview@example.com',
            str_contains($name, 'url'), str_contains($name, 'link') => 'https://example.com',
            str_contains($name, 'name') => 'Jane Doe',
            str_ends_with($name, 'id') => 'ORD-1001',
            default => 'Sample text',
        };
    }

    public function isOverridable(ReflectionParameter $parameter): bool
    {
        $type = $this->namedTypeName($parameter->getType());

        if ($type === null && $parameter->getType() === null) {
            return true;
        }

        return $this->isOverridableType($type);
    }

    protected function isOverridableType(?string $type): bool
    {
        if (! $type) {
            return false;
        }

        return in_array($type, ['int', 'integer', 'float', 'double', 'bool', 'boolean', 'string'], true)
            || is_a($type, DateTimeInterface::class, true);
    }

    protected function namedTypeName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && $type->getName() !== 'null') {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $types = collect($type->getTypes())
                ->filter(fn ($unionType) => $unionType instanceof ReflectionNamedType && $unionType->getName() !== 'null')
                ->map(fn (ReflectionNamedType $unionType) => $unionType->getName())
                ->unique()
                ->values();

            if ($types->count() === 1) {
                return $types->first();
            }
        }

        return null;
    }

    protected function castOverride(ReflectionParameter $parameter, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            if ($parameter->allowsNull()) {
                return null;
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
        }

        $type = $this->namedTypeName($parameter->getType());

        if ($type && is_a($type, DateTimeInterface::class, true)) {
            return Carbon::parse($value);
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value === null ? '' : (string) $value,
        };
    }
}
