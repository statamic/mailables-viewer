<?php

namespace Tests;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\MailablesViewer\DiscoveredMailables;
use Statamic\MailablesViewer\MailableFactory;
use Statamic\MailablesViewer\Mailables;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\Fixtures\Mail\AbstractMail;
use Tests\Fixtures\Mail\BrokenMail;
use Tests\Fixtures\Mail\FixedLocaleMail;
use Tests\Fixtures\Mail\LocalizedMail;
use Tests\Fixtures\Mail\Nested\OrderShipped;
use Tests\Fixtures\Mail\NotAMailable;
use Tests\Fixtures\Mail\QueuedMail;
use Tests\Fixtures\Mail\ResolvableMail;
use Tests\Fixtures\Mail\ScalarMail;
use Tests\Fixtures\Mail\UserMail;
use Tests\Fixtures\Mail\WelcomeMail;

class MailablesTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Mailables::flush();
    }

    protected function tearDown(): void
    {
        Mailables::flush();

        parent::tearDown();
    }

    #[Test]
    public function it_discovers_concrete_mailables_and_ignores_the_rest()
    {
        $classes = (new DiscoveredMailables)->all(
            __DIR__.'/Fixtures/Mail',
            'Tests\\Fixtures\\Mail'
        );

        $this->assertTrue($classes->contains(WelcomeMail::class));
        $this->assertTrue($classes->contains(QueuedMail::class));
        $this->assertTrue($classes->contains(OrderShipped::class));
        $this->assertFalse($classes->contains(AbstractMail::class));
        $this->assertFalse($classes->contains(NotAMailable::class));
    }

    #[Test]
    public function it_includes_explicitly_registered_mailables()
    {
        Mailables::register(WelcomeMail::class);
        Mailables::register([QueuedMail::class, OrderShipped::class]);

        $classes = Mailables::classes();

        $this->assertTrue($classes->contains(WelcomeMail::class));
        $this->assertTrue($classes->contains(QueuedMail::class));
        $this->assertTrue($classes->contains(OrderShipped::class));
    }

    #[Test]
    public function factory_fills_scalar_datetime_and_defaulted_constructor_params()
    {
        $mailable = (new MailableFactory)->make(ScalarMail::class);

        $this->assertSame('Jane Doe', $mailable->name);
        $this->assertSame('preview@example.com', $mailable->email);
        $this->assertSame('https://example.com', $mailable->url);
        $this->assertSame(42, $mailable->count);
        $this->assertTrue($mailable->active);
        $this->assertSame([], $mailable->items);
        $this->assertInstanceOf(DateTimeInterface::class, $mailable->when);
        $this->assertSame('default-value', $mailable->optional);
        $this->assertNull($mailable->nullable);
    }

    #[Test]
    public function factory_applies_overridable_constructor_overrides()
    {
        $mailable = (new MailableFactory)->make(ScalarMail::class, [
            'name' => 'Jack',
            'count' => '7',
            'active' => 'false',
            'when' => '2026-08-19T20:48',
            'items' => ['ignored'],
        ]);

        $this->assertSame('Jack', $mailable->name);
        $this->assertSame(7, $mailable->count);
        $this->assertFalse($mailable->active);
        $this->assertSame('2026-08-19 20:48:00', $mailable->when->format('Y-m-d H:i:s'));
        $this->assertSame([], $mailable->items);
    }

    #[Test]
    public function factory_resolves_statamic_user_and_container_classes()
    {
        $user = tap(User::make()->email('jane@example.com'))->save();

        $userMail = (new MailableFactory)->make(UserMail::class);
        $this->assertSame($user->email(), $userMail->user->email());

        $resolvable = (new MailableFactory)->make(ResolvableMail::class);
        $this->assertSame('Hello from the container', $resolvable->greeting->message());
    }

    #[Test]
    public function details_include_template_queue_and_constructor_sample_data()
    {
        Mailables::register([WelcomeMail::class, QueuedMail::class, ScalarMail::class]);

        $welcome = Mailables::describe(WelcomeMail::class);
        $this->assertFalse($welcome['details']['queued']);
        $this->assertSame('HTML', $welcome['details']['template']['engine']);

        $queued = Mailables::describe(QueuedMail::class);
        $this->assertTrue($queued['details']['queued']);

        $scalar = Mailables::describe(ScalarMail::class);
        $params = collect($scalar['details']['constructor'])->keyBy('name');
        $this->assertSame('preview@example.com', $params['email']['value']);
        $this->assertSame('string', $params['email']['type']);
        $this->assertTrue($params['email']['editable']);
        $this->assertSame('email', $params['email']['input']);
        $this->assertSame('Jane Doe', $params['name']['value']);
        $this->assertSame('default-value', $params['optional']['value']);
        $this->assertTrue($params['active']['editable']);
        $this->assertSame('checkbox', $params['active']['input']);
        $this->assertTrue($params['active']['value']);
        $this->assertFalse($params['items']['editable']);
        $this->assertSame('datetime-local', $params['when']['input']);
    }

    #[Test]
    public function details_find_event_listeners_that_send_the_mailable()
    {
        File::ensureDirectoryExists(app_path('Listeners'));
        File::put(app_path('Listeners/SendWelcomeMail.php'), <<<'PHP'
<?php

namespace App\Listeners;

use Tests\Fixtures\Mail\WelcomeMail;

class SendWelcomeMail
{
    public function handle(\App\Events\UserRegistered $event)
    {
        \Mail::to($event->user)->send(new WelcomeMail);
    }
}
PHP);

        require_once app_path('Listeners/SendWelcomeMail.php');

        Mailables::register(WelcomeMail::class);

        $details = Mailables::describe(WelcomeMail::class)['details'];

        $this->assertSame('App\\Events\\UserRegistered', $details['events'][0]['event']);
        $this->assertSame('App\\Listeners\\SendWelcomeMail', $details['events'][0]['listener']);
        $this->assertFalse(
            collect($details['references'])->contains(
                fn ($reference) => str_contains($reference['file'], 'SendWelcomeMail.php')
            )
        );
    }

    #[Test]
    public function index_includes_envelope_metadata_and_error_flags()
    {
        Mailables::register([WelcomeMail::class, QueuedMail::class, BrokenMail::class]);

        $user = $this->superUser();

        $this
            ->actingAs($user)
            ->get(cp_route('utilities.mailables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('mailables-viewer::Mailables')
                ->has('mailables', 3)
                ->where('mailables.0.class', BrokenMail::class)
                ->where('mailables.0.error', 'Cannot instantiate broken mailable.')
                ->where('mailables.1.class', QueuedMail::class)
                ->where('mailables.1.subject', 'Queued')
                ->where('mailables.1.queued', true)
                ->where('mailables.1.error', null)
                ->where('mailables.2.class', WelcomeMail::class)
                ->where('mailables.2.name', 'Welcome Mail')
                ->where('mailables.2.path', fn ($path) => str_ends_with($path, 'Fixtures/Mail/WelcomeMail.php'))
                ->where('mailables.2.subject', 'Welcome!')
                ->where('mailables.2.from', 'Acme <hello@example.com>')
                ->where('mailables.2.queued', false)
                ->where('mailables.2.details.queued', false)
                ->where('mailables.2.details.template.engine', 'HTML')
                ->has('previewUrl')
                ->has('metaUrl')
                ->has('sendUrl')
                ->where('defaultEmail', 'jane@example.com')
            );
    }

    #[Test]
    public function index_preselects_the_mailable_named_in_the_query_string()
    {
        Mailables::register([WelcomeMail::class, QueuedMail::class]);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables', ['mailable' => WelcomeMail::class]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialMailable', WelcomeMail::class)
            );
    }

    #[Test]
    public function index_preselects_the_first_mailable_without_a_query_string()
    {
        Mailables::register([WelcomeMail::class, QueuedMail::class]);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialMailable', QueuedMail::class)
            );
    }

    #[Test]
    public function index_preselects_the_first_mailable_for_a_mailable_it_does_not_have()
    {
        Mailables::register([WelcomeMail::class, QueuedMail::class]);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables', ['mailable' => NotAMailable::class]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('initialMailable', QueuedMail::class)
            );
    }

    #[Test]
    public function index_preselects_nothing_when_there_are_no_mailables()
    {
        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('mailables', 0)
                ->where('initialMailable', null)
            );
    }

    #[Test]
    public function index_describes_mailables_in_the_selected_site_by_default()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site', 'en')
                ->has('sites', 2)
                ->where('sites.1.handle', 'fr_ch')
                ->where('sites.1.name', 'French')
                ->where('sites.1.url', cp_route('utilities.mailables', ['site' => 'fr_ch']))
                ->where('mailables.0.subject', 'Subject in en')
            );
    }

    #[Test]
    public function index_describes_mailables_in_the_site_named_in_the_query_string()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables', ['site' => 'fr_ch']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site', 'fr_ch')
                ->where('mailables.0.subject', 'Subject in fr')
            );
    }

    #[Test]
    public function index_ignores_a_site_the_user_may_not_view()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);
        $this->setTestRoles(['test' => ['access cp', 'access mailables utility', 'access en site']]);
        $user = tap(User::make()->email('limited@example.com')->assignRole('test'))->save();

        $this
            ->actingAs($user)
            ->get(cp_route('utilities.mailables', ['site' => 'fr_ch']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site', 'en')
                ->has('sites', 1)
                ->where('mailables.0.subject', 'Subject in en')
            );
    }

    #[Test]
    public function index_has_one_site_and_the_cp_locale_on_a_single_site_install()
    {
        Mailables::register(LocalizedMail::class);
        $expected = 'Subject in '.app()->getLocale();

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 1)
                ->where('mailables.0.subject', $expected)
            );
    }

    #[Test]
    public function index_ignores_a_site_that_is_not_a_handle()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables', ['site' => ['fr_ch']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('site', 'en'));
    }

    #[Test]
    public function preview_returns_rendered_html()
    {
        Mailables::register(WelcomeMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', ['mailable' => WelcomeMail::class]))
            ->assertOk()
            ->assertSee('Welcome to the list.', false);
    }

    #[Test]
    public function preview_uses_injected_constructor_values()
    {
        Mailables::register(ScalarMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', [
                'mailable' => ScalarMail::class,
                'values' => ['name' => 'Jack'],
            ]))
            ->assertOk()
            ->assertSee('Hello Jack', false);
    }

    #[Test]
    public function meta_returns_envelope_for_injected_values()
    {
        Mailables::register(ScalarMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.meta', [
                'mailable' => ScalarMail::class,
                'values' => ['name' => 'Jack'],
            ]))
            ->assertOk()
            ->assertJson([
                'subject' => 'Hello Jack',
                'error' => null,
            ]);
    }

    #[Test]
    public function preview_rejects_non_whitelisted_mailables()
    {
        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', ['mailable' => WelcomeMail::class]))
            ->assertNotFound();
    }

    #[Test]
    public function send_sends_immediately_even_for_queued_mailables()
    {
        Mail::fake();
        Mailables::register(QueuedMail::class);

        $this
            ->actingAs($this->superUser())
            ->from(cp_route('utilities.mailables'))
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => QueuedMail::class,
            ])
            ->assertRedirect(cp_route('utilities.mailables'));

        Mail::assertSent(QueuedMail::class, function (QueuedMail $mail) {
            return $mail->hasTo('tester@example.com');
        });

        Mail::assertNotQueued(QueuedMail::class);
    }

    #[Test]
    public function send_uses_injected_constructor_values()
    {
        Mail::fake();
        Mailables::register(ScalarMail::class);

        $this
            ->actingAs($this->superUser())
            ->from(cp_route('utilities.mailables'))
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => ScalarMail::class,
                'values' => ['name' => 'Jack'],
            ])
            ->assertRedirect(cp_route('utilities.mailables'));

        Mail::assertSent(ScalarMail::class, function (ScalarMail $mail) {
            return $mail->name === 'Jack' && $mail->hasTo('tester@example.com');
        });
    }

    #[Test]
    public function send_validates_email_and_mailable()
    {
        Mail::fake();
        Mailables::register(WelcomeMail::class);

        $this
            ->actingAs($this->superUser())
            ->from(cp_route('utilities.mailables'))
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'not-an-email',
                'mailable' => WelcomeMail::class,
            ])
            ->assertSessionHasErrors('email');

        $this
            ->actingAs($this->superUser())
            ->from(cp_route('utilities.mailables'))
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => 'App\\Evil\\Mailable',
            ])
            ->assertSessionHasErrors('mailable');

        Mail::assertNothingSent();
    }

    #[Test]
    public function preview_renders_in_the_requested_site()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', ['mailable' => LocalizedMail::class, 'site' => 'fr_ch']))
            ->assertOk()
            ->assertSee('Site fr_ch in fr', false);
    }

    #[Test]
    public function preview_falls_back_to_the_selected_site_for_an_unknown_site()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', ['mailable' => LocalizedMail::class, 'site' => 'nope']))
            ->assertOk()
            ->assertSee('Site en in en', false);
    }

    #[Test]
    public function meta_returns_the_envelope_of_the_requested_site()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->getJson(cp_route('utilities.mailables.meta', ['mailable' => LocalizedMail::class, 'site' => 'fr_ch']))
            ->assertOk()
            ->assertJsonPath('subject', 'Subject in fr');
    }

    #[Test]
    public function send_renders_in_the_requested_site()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);

        $this
            ->actingAs($this->superUser())
            ->from(cp_route('utilities.mailables'))
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => LocalizedMail::class,
                'site' => 'fr_ch',
            ])
            ->assertRedirect(cp_route('utilities.mailables'));

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertSame('Subject in fr', $sent->getSubject());
        $this->assertStringContainsString('Site fr_ch in fr', $sent->getHtmlBody());
    }

    #[Test]
    public function send_and_preview_keep_a_locale_the_mailable_sets_itself()
    {
        $this->setSites();
        $this->app['view']->addLocation(__DIR__.'/Fixtures/views');
        Mailables::register(FixedLocaleMail::class);

        $this
            ->actingAs($this->superUser())
            ->get(cp_route('utilities.mailables.preview', ['mailable' => FixedLocaleMail::class, 'site' => 'fr_ch']))
            ->assertSee('Rendered in de', false);

        $this
            ->actingAs($this->superUser())
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => FixedLocaleMail::class,
                'site' => 'fr_ch',
            ]);

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertStringContainsString('Rendered in de', $sent->getHtmlBody());
    }

    #[Test]
    public function send_leaves_the_locale_alone_on_a_single_site_install()
    {
        Mailables::register(LocalizedMail::class);
        app()->setLocale('de');

        $this
            ->actingAs($this->superUser())
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => LocalizedMail::class,
            ]);

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertSame('Subject in de', $sent->getSubject());
    }

    #[Test]
    public function preview_meta_and_send_ignore_a_site_the_user_may_not_view()
    {
        $this->setSites();
        Mailables::register(LocalizedMail::class);
        $this->setTestRoles(['test' => ['access cp', 'access mailables utility', 'access en site']]);
        $user = tap(User::make()->email('limited@example.com')->assignRole('test'))->save();

        $this
            ->actingAs($user)
            ->get(cp_route('utilities.mailables.preview', ['mailable' => LocalizedMail::class, 'site' => 'fr_ch']))
            ->assertOk()
            ->assertSee('Site en in en', false);

        $this
            ->actingAs($user)
            ->getJson(cp_route('utilities.mailables.meta', ['mailable' => LocalizedMail::class, 'site' => 'fr_ch']))
            ->assertOk()
            ->assertJson(['subject' => 'Subject in en']);

        $this
            ->actingAs($user)
            ->post(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => LocalizedMail::class,
                'site' => 'fr_ch',
            ]);

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertSame('Subject in en', $sent->getSubject());
    }

    #[Test]
    public function routes_are_forbidden_without_permission()
    {
        $this->setTestRoles(['test' => ['access cp']]);
        $user = tap(User::make()->email('limited@example.com')->assignRole('test'))->save();

        $this
            ->actingAs($user)
            ->get(cp_route('utilities.mailables'))
            ->assertRedirect(cp_route('index'));

        $this
            ->actingAs($user)
            ->getJson(cp_route('utilities.mailables'))
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->getJson(cp_route('utilities.mailables.preview', ['mailable' => WelcomeMail::class]))
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->getJson(cp_route('utilities.mailables.meta', ['mailable' => WelcomeMail::class]))
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->postJson(cp_route('utilities.mailables.send'), [
                'email' => 'tester@example.com',
                'mailable' => WelcomeMail::class,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function routes_are_allowed_with_permission()
    {
        Mailables::register(WelcomeMail::class);

        $this->setTestRoles(['test' => ['access cp', 'access mailables utility']]);
        $user = tap(User::make()->email('allowed@example.com')->assignRole('test'))->save();

        $this
            ->actingAs($user)
            ->get(cp_route('utilities.mailables'))
            ->assertOk();
    }
}
