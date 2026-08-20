<?php

namespace Statamic\MailablesViewer;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

class DiscoveredMailables
{
    public function all(?string $path = null, ?string $namespace = null): Collection
    {
        $path ??= app_path('Mail');
        $namespace ??= rtrim(app()->getNamespace(), '\\').'\\Mail';

        if (! File::isDirectory($path)) {
            return collect();
        }

        return collect(File::allFiles($path))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file) => $this->classFromFile($file, $path, $namespace))
            ->filter()
            ->filter(fn (string $class) => $this->isConcreteMailable($class))
            ->values();
    }

    protected function classFromFile(SplFileInfo $file, string $path, string $namespace): ?string
    {
        $base = rtrim(str_replace('\\', '/', $path), '/');
        $full = str_replace('\\', '/', $file->getPathname());
        $relative = Str::after($full, $base.'/');
        $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

        return class_exists($class) ? $class : null;
    }

    protected function isConcreteMailable(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Mailable::class)) {
            return false;
        }

        return ! (new ReflectionClass($class))->isAbstract();
    }
}
