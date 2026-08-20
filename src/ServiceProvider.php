<?php

namespace Statamic\MailablesViewer;

use Statamic\MailablesViewer\Http\Controllers\MailablesController;
use Statamic\Facades\User;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

use function Statamic\trans as __;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    public function bootAddon(): void
    {
        Utility::extend(function ($utilities) {
            $utilities->register('mailables')
                ->inertia('mailables-viewer::Mailables', fn () => [
                    'mailables' => Mailables::all()->values(),
                    'previewUrl' => cp_route('utilities.mailables.preview'),
                    'metaUrl' => cp_route('utilities.mailables.meta'),
                    'sendUrl' => cp_route('utilities.mailables.send'),
                    'defaultEmail' => User::current()?->email(),
                ])
                ->title(__('mailables-viewer::messages.title'))
                ->navTitle(__('mailables-viewer::messages.nav_title'))
                ->icon('mail-inbox-content')
                ->description(__('mailables-viewer::messages.description'))
                ->routes(function ($router) {
                    $router->get('preview', [MailablesController::class, 'preview'])->name('preview');
                    $router->get('meta', [MailablesController::class, 'meta'])->name('meta');
                    $router->post('send', [MailablesController::class, 'send'])->name('send');
                });
        });
    }
}
