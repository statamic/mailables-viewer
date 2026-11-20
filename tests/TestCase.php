<?php

namespace Tests;

use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\MailablesViewer\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.editions.pro', true);
    }

    protected function setSites(): void
    {
        config(['statamic.system.multisite' => true]);

        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'fr_ch' => ['name' => 'French', 'url' => '/fr/', 'locale' => 'fr_CH', 'lang' => 'fr'],
        ]);
    }

    protected function superUser()
    {
        return tap(User::make()->email('jane@example.com')->makeSuper())->save();
    }
}
