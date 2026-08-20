<?php

namespace Statamic\MailablesViewer;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\Finder\SplFileInfo;

class MailableInspector
{
    /**
     * @return array{queued: bool, queue: string|null, template: array{engine: string|null, view: string|null, path: string|null, text_view: string|null}, constructor: array<int, array{name: string, label: string, type: string|null, value: mixed, input: string|null, editable: bool}>, events: array<int, array{event: string, listener: string}>, references: array<int, array{file: string, kind: string}>}
     */
    public function inspect(string $class, ?Mailable $mailable = null): array
    {
        return [
            'queued' => is_subclass_of($class, ShouldQueue::class) || $mailable instanceof ShouldQueue,
            'queue' => $mailable && property_exists($mailable, 'queue') ? $mailable->queue : null,
            'template' => $this->template($mailable),
            'constructor' => $mailable ? $this->constructor($mailable) : [],
            'events' => $this->events($class),
            'references' => $this->references($class),
        ];
    }

    /**
     * @return array{engine: string|null, view: string|null, path: string|null, text_view: string|null}
     */
    protected function template(?Mailable $mailable): array
    {
        $template = [
            'engine' => null,
            'view' => null,
            'path' => null,
            'text_view' => null,
        ];

        if (! $mailable) {
            return $template;
        }

        $markdown = $mailable->markdown;
        $view = $mailable->view;
        $text = $mailable->textView;
        $htmlString = false;

        if (method_exists($mailable, 'content')) {
            $content = $mailable->content();

            if ($content instanceof Content) {
                $markdown = $content->markdown ?? $markdown;
                $view = $content->view ?? $content->html ?? $view;
                $text = $content->text ?? $text;
                $htmlString = filled($content->htmlString);
            }
        }

        $viewName = $markdown ?? $view;

        $template['view'] = $viewName;
        $template['text_view'] = $text;
        $template['path'] = $viewName ? $this->viewPath($viewName) : null;
        $template['engine'] = $this->engine($markdown, $htmlString, $template['path']);

        return $template;
    }

    protected function engine(mixed $markdown, bool $htmlString, ?string $path): ?string
    {
        if (filled($markdown)) {
            return 'Markdown';
        }

        if ($path && str_contains($path, '.antlers.')) {
            return 'Antlers';
        }

        if ($htmlString) {
            return 'HTML';
        }

        if ($path) {
            return 'Blade';
        }

        return null;
    }

    protected function viewPath(string $view): ?string
    {
        try {
            $path = view()->getFinder()->find($view);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->relativePath($path);
    }

    /**
     * @return array<int, array{name: string, label: string, type: string|null, value: mixed, input: string|null, editable: bool}>
     */
    protected function constructor(Mailable $mailable): array
    {
        $constructor = (new ReflectionClass($mailable))->getConstructor();

        if (! $constructor || $constructor->getNumberOfParameters() === 0) {
            return [];
        }

        return collect($constructor->getParameters())
            ->map(function (ReflectionParameter $parameter) use ($mailable) {
                $name = $parameter->getName();
                $type = $this->namedTypeName($parameter->getType());
                $input = $this->inputFor($parameter);
                $value = $mailable->{$name} ?? null;

                return [
                    'name' => $name,
                    'label' => Str::headline($name),
                    'type' => $type,
                    'value' => $input ? $this->editValue($value, $input) : $this->stringify($value),
                    'input' => $input,
                    'editable' => $input !== null,
                ];
            })
            ->all();
    }

    protected function inputFor(ReflectionParameter $parameter): ?string
    {
        $type = $this->namedTypeName($parameter->getType());

        if ($type === null && $parameter->getType() !== null) {
            return null;
        }

        if ($type && is_a($type, DateTimeInterface::class, true)) {
            return 'datetime-local';
        }

        return match ($type) {
            'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'checkbox',
            'string', null => $this->stringInput($parameter->getName()),
            default => null,
        };
    }

    protected function stringInput(string $name): string
    {
        $name = Str::lower($name);

        return match (true) {
            str_contains($name, 'email') => 'email',
            str_contains($name, 'url'), str_contains($name, 'link') => 'url',
            default => 'text',
        };
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

    protected function editValue(mixed $value, string $input): mixed
    {
        if ($value === null) {
            return $input === 'checkbox' ? false : '';
        }

        if ($input === 'checkbox') {
            return (bool) $value;
        }

        if ($input === 'number' && is_numeric($value)) {
            return $value + 0;
        }

        if ($input === 'datetime-local' && $value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i');
        }

        if (is_scalar($value)) {
            return $value;
        }

        return $this->stringify($value);
    }

    /**
     * @return array<int, array{event: string, listener: string}>
     */
    protected function events(string $class): array
    {
        $basename = class_basename($class);

        return $this->appFiles()
            ->filter(fn (SplFileInfo $file) => $this->fileReferences($file, $class, $basename))
            ->map(function (SplFileInfo $file) {
                $listener = $this->classFromFile($file);

                if (! $listener || ! class_exists($listener)) {
                    return null;
                }

                $event = $this->eventForListener($listener);

                if (! $event) {
                    return null;
                }

                return [
                    'event' => $event,
                    'listener' => $listener,
                ];
            })
            ->filter()
            ->unique(fn (array $item) => $item['event'].$item['listener'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{file: string, kind: string}>
     */
    protected function references(string $class): array
    {
        $basename = class_basename($class);
        $ownFile = (new ReflectionClass($class))->getFileName();
        $eventListeners = collect($this->events($class))->pluck('listener');

        return $this->appFiles()
            ->reject(fn (SplFileInfo $file) => $ownFile && $file->getRealPath() === realpath($ownFile))
            ->filter(fn (SplFileInfo $file) => $this->fileReferences($file, $class, $basename))
            ->reject(function (SplFileInfo $file) use ($eventListeners) {
                $class = $this->classFromFile($file);

                return $class && $eventListeners->contains($class);
            })
            ->map(fn (SplFileInfo $file) => [
                'file' => $this->relativePath($file->getPathname()),
                'kind' => $this->referenceKind($file),
            ])
            ->values()
            ->all();
    }

    protected function fileReferences(SplFileInfo $file, string $class, string $basename): bool
    {
        $contents = $file->getContents();

        return str_contains($contents, $class)
            || preg_match('/\b'.preg_quote($basename, '/').'\b/', $contents);
    }

    protected function eventForListener(string $listener): ?string
    {
        $reflection = new ReflectionClass($listener);

        if (! $reflection->hasMethod('handle')) {
            return null;
        }

        $parameter = $reflection->getMethod('handle')->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    protected function referenceKind(SplFileInfo $file): string
    {
        $path = str_replace('\\', '/', $file->getPathname());

        return match (true) {
            str_contains($path, '/Listeners/') => 'Listener',
            str_contains($path, '/Jobs/') => 'Job',
            str_contains($path, '/Http/Controllers/') => 'Controller',
            str_contains($path, '/Observers/') => 'Observer',
            default => 'Class',
        };
    }

    protected function classFromFile(SplFileInfo $file): ?string
    {
        $contents = $file->getContents();

        if (! preg_match('/namespace\s+([^;]+);/', $contents, $namespace)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $contents, $class)) {
            return null;
        }

        return $namespace[1].'\\'.$class[1];
    }

    protected function appFiles(): Collection
    {
        return once(function () {
            if (! File::isDirectory(app_path())) {
                return collect();
            }

            return collect(File::allFiles(app_path()))
                ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
                ->values();
        });
    }

    protected function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return Str::startsWith($path, $base) ? Str::after($path, $base) : $path;
    }

    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_array($value)) {
            return empty($value) ? '[]' : json_encode($value);
        }

        return get_debug_type($value);
    }
}
