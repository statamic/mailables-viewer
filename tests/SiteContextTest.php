<?php

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\MailablesViewer\SiteContext;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class SiteContextTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function it_resolves_the_requested_site()
    {
        $this->setSites();
        $this->actingAs($this->superUser());

        $this->assertSame('fr_ch', SiteContext::resolve('fr_ch')->handle());
    }

    #[Test]
    public function it_falls_back_to_the_selected_site()
    {
        $this->setSites();
        $this->actingAs($this->superUser());
        Site::setSelected('fr_ch');

        $this->assertSame('fr_ch', SiteContext::resolve(null)->handle());
        $this->assertSame('fr_ch', SiteContext::resolve('nope')->handle());
    }

    #[Test]
    public function it_never_resolves_a_site_the_user_may_not_view()
    {
        $this->setSites();
        $this->setTestRoles(['test' => ['access cp', 'access mailables utility', 'access en site']]);
        $this->actingAs(tap(User::make()->email('limited@example.com')->assignRole('test'))->save());
        Site::setSelected('fr_ch');

        $this->assertSame('en', SiteContext::resolve('fr_ch')->handle());
        $this->assertSame('en', SiteContext::resolve(null)->handle());
        $this->assertSame(['en'], array_column(SiteContext::options(), 'handle'));
    }

    #[Test]
    public function it_runs_a_callback_in_the_sites_locale_and_restores_it()
    {
        $this->setSites();
        $before = [app()->getLocale(), Site::current()->handle()];

        $inside = SiteContext::run(Site::get('fr_ch'), fn () => [app()->getLocale(), Site::current()->handle()]);

        $this->assertSame(['fr', 'fr_ch'], $inside);
        $this->assertSame($before, [app()->getLocale(), Site::current()->handle()]);
    }

    #[Test]
    public function it_restores_locale_and_site_when_the_callback_throws()
    {
        $this->setSites();
        $before = [app()->getLocale(), Site::current()->handle()];

        try {
            SiteContext::run(Site::get('fr_ch'), fn () => throw new \RuntimeException('boom'));
            $this->fail('Expected the exception to bubble up.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, [app()->getLocale(), Site::current()->handle()]);
    }

    #[Test]
    public function it_does_not_pin_the_current_site_afterwards()
    {
        $this->setSites();

        SiteContext::run(Site::get('fr_ch'), fn () => null);

        Site::resolveCurrentUrlUsing(fn () => 'http://localhost/fr/page');

        $this->assertSame('fr_ch', Site::current()->handle());
    }

    #[Test]
    public function it_restores_a_current_site_that_was_set_explicitly()
    {
        $this->setSites();
        Site::setCurrent('fr_ch');

        SiteContext::run(Site::get('en'), fn () => null);

        Site::resolveCurrentUrlUsing(fn () => 'http://localhost/');

        $this->assertSame('fr_ch', Site::current()->handle());
    }

    #[Test]
    public function it_leaves_a_single_site_install_alone()
    {
        app()->setLocale('de');

        $this->assertSame('de', SiteContext::run(Site::default(), fn () => app()->getLocale()));
    }

    #[Test]
    public function options_link_each_site_to_the_utility()
    {
        $this->setSites();
        $this->actingAs($this->superUser());

        $this->assertSame([
            ['handle' => 'en', 'name' => 'English', 'url' => cp_route('utilities.mailables', ['site' => 'en'])],
            ['handle' => 'fr_ch', 'name' => 'French', 'url' => cp_route('utilities.mailables', ['site' => 'fr_ch'])],
        ], SiteContext::options());
    }
}
