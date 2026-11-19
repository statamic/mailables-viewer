<?php

namespace Statamic\MailablesViewer;

use Closure;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteInstance;

class SiteContext
{
    public static function resolve(mixed $handle): SiteInstance
    {
        $sites = Site::authorized();
        $selected = Site::selected()->handle();

        return $sites->get(is_string($handle) ? $handle : '') ?? $sites->get($selected) ?? $sites->first() ?? Site::default();
    }

    public static function run(SiteInstance $site, Closure $callback): mixed
    {
        if (! Site::hasMultiple()) {
            return $callback();
        }

        $originalLocale = app()->getLocale();
        $originalSite = Site::current();

        app()->setLocale($site->lang());
        Site::setCurrent($site->handle());

        try {
            return $callback();
        } finally {
            app()->setLocale($originalLocale);
            Site::setCurrent($originalSite->handle());
        }
    }

    /**
     * @return array<int, array{handle: string, name: string, url: string}>
     */
    public static function options(): array
    {
        return Site::authorized()
            ->map(fn (SiteInstance $site) => [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'url' => cp_route('utilities.mailables', ['site' => $site->handle()]),
            ])
            ->values()
            ->all();
    }
}
