<?php

namespace Statamic\MailablesViewer;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;

class Mailables
{
    protected static array $registered = [];

    public static function register(string|array $mailables): void
    {
        foreach (Arr::wrap($mailables) as $mailable) {
            if (! in_array($mailable, static::$registered, true)) {
                static::$registered[] = $mailable;
            }
        }
    }

    public static function registered(): array
    {
        return static::$registered;
    }

    public static function flush(): void
    {
        static::$registered = [];
    }

    public static function classes(): Collection
    {
        return (new DiscoveredMailables)->all()
            ->merge(static::$registered)
            ->unique()
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Mailable::class))
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, MailableDescription>
     */
    public static function all(): Collection
    {
        return static::classes()
            ->map(fn (string $class) => static::describe($class))
            ->values();
    }

    public static function contains(string $class): bool
    {
        return static::classes()->contains($class);
    }

    public static function make(string $class, array $overrides = []): Mailable
    {
        if (! static::contains($class)) {
            throw new \InvalidArgumentException("Mailable [{$class}] is not available.");
        }

        return (new MailableFactory)->make($class, $overrides);
    }

    public static function describe(string $class, array $overrides = []): MailableDescription
    {
        $inspector = new MailableInspector;
        $path = static::relativePath($class);
        $name = Str::headline(class_basename($class));

        try {
            $instance = (new MailableFactory)->make($class, $overrides);
            $envelope = static::envelope($instance);

            return new MailableDescription(
                class: $class,
                name: $name,
                path: $path,
                subject: $envelope['subject'],
                from: $envelope['from'],
                attachments: $envelope['attachments'],
                queued: $instance instanceof ShouldQueue,
                error: null,
                details: $inspector->inspect($class, $instance),
            );
        } catch (\Throwable $e) {
            return new MailableDescription(
                class: $class,
                name: $name,
                path: $path,
                subject: null,
                from: null,
                attachments: 0,
                queued: is_subclass_of($class, ShouldQueue::class),
                error: $e->getMessage(),
                details: $inspector->inspect($class),
            );
        }
    }

    /**
     * @return array{subject: string|null, from: string|null, attachments: int}
     */
    protected static function envelope(Mailable $mailable): array
    {
        $subject = $mailable->subject;
        $from = static::formatFrom($mailable->from);
        $attachments = static::attachmentsCount($mailable);

        if (method_exists($mailable, 'envelope')) {
            $envelope = $mailable->envelope();
            $subject = $envelope->subject ?? $subject;

            if ($envelope->from instanceof Address) {
                $from = static::formatAddress($envelope->from->address, $envelope->from->name);
            }
        } elseif (method_exists($mailable, 'build')) {
            $mailable->build();
            $subject = $mailable->subject ?? $subject;
            $from = static::formatFrom($mailable->from) ?? $from;
            $attachments = static::attachmentsCount($mailable);
        }

        return [
            'subject' => $subject,
            'from' => $from ?? static::defaultFrom(),
            'attachments' => $attachments,
        ];
    }

    protected static function attachmentsCount(Mailable $mailable): int
    {
        if (method_exists($mailable, 'attachments')) {
            try {
                return count($mailable->attachments());
            } catch (\Throwable) {
                //
            }
        }

        return count($mailable->attachments ?? [])
            + count($mailable->rawAttachments ?? [])
            + count($mailable->diskAttachments ?? []);
    }

    protected static function formatFrom(mixed $from): ?string
    {
        if (empty($from) || ! is_array($from)) {
            return null;
        }

        $first = $from[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        return static::formatAddress($first['address'] ?? null, $first['name'] ?? null);
    }

    protected static function formatAddress(?string $address, ?string $name = null): ?string
    {
        if (! $address) {
            return null;
        }

        return $name ? "{$name} <{$address}>" : $address;
    }

    protected static function defaultFrom(): ?string
    {
        return static::formatAddress(config('mail.from.address'), config('mail.from.name'));
    }

    protected static function relativePath(string $class): ?string
    {
        $file = (new ReflectionClass($class))->getFileName();

        if (! $file) {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return Str::startsWith($file, $base) ? Str::after($file, $base) : $file;
    }
}
