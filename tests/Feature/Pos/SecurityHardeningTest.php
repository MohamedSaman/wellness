<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\SecuritySettings;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Http\Middleware\LogOutIdleUsers;
use App\Http\Middleware\SecurityHeaders;
use App\Models\AuditLog;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\PasswordRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

/**
 * Security for an install on the public internet (docs/deploy/CLOUD.md,
 * Security): two-factor authentication required for the owner, the admin
 * panel signed in through Fortify, login throttling per address, idle
 * sign-out, security headers, the admin IP allowlist, audit log retention.
 */
class SecurityHardeningTest extends AdminTestCase
{
    private function requireTwoFactor(): void
    {
        config(['pos.security.require_two_factor' => true]);
    }

    private function confirmTwoFactor(): void
    {
        $this->owner->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_an_owner_without_two_factor_is_sent_to_set_it_up_from_every_page(): void
    {
        $this->requireTwoFactor();
        $this->actingAs($this->owner);

        $this->get('/admin')->assertRedirect(route('security.edit'));
        $this->get(route('pos.index'))->assertRedirect(route('security.edit'));
        $this->getJson(route('pos.index'))->assertForbidden()->assertJson(['message' => 'Your account needs two-factor authentication. Turn it on to continue.']);

        // Setting it up stays reachable (after confirming the password), and so does signing out.
        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
        $this->post(route('two-factor.enable'))->assertRedirect();
        $this->post(route('logout'))->assertRedirect();
    }

    public function test_an_owner_with_two_factor_and_a_cashier_without_it_get_in(): void
    {
        $this->requireTwoFactor();
        $this->confirmTwoFactor();

        $this->actingAs($this->owner)->get('/admin')->assertOk();
        // Optional for everyone else.
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertOk();
    }

    public function test_two_factor_is_not_enforced_unless_switched_on(): void
    {
        config(['pos.security.require_two_factor' => false]);

        $this->actingAs($this->owner)->get('/admin')->assertOk();
    }

    public function test_the_admin_panel_signs_in_through_fortify_so_the_owner_gets_the_two_factor_challenge(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertNotFound();

        $this->confirmTwoFactor();

        $this->post(route('login.store'), ['email' => 'owner@pos.test', 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_login_is_throttled_per_address_whatever_the_email(): void
    {
        config(['pos.security.login_attempts_per_ip' => 3]);
        RateLimiter::clear('ip:127.0.0.1');

        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
            $this->post(route('login.store'), ['email' => $email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => 'owner@pos.test', 'password' => 'password'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_an_idle_user_is_signed_out_and_background_refreshes_do_not_count_as_activity(): void
    {
        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'admin_idle_minutes' => 30, 'till_idle_minutes' => 120]]);
        $this->actingAs($this->owner);

        $this->get('/admin')->assertOk();
        $start = session(LogOutIdleUsers::SESSION_KEY);

        $this->travel(20)->minutes();
        // The dashboard's own refresh: no activity.
        $this->withHeader('X-Background', '1')->get('/admin')->assertOk();
        $this->assertSame($start, session(LogOutIdleUsers::SESSION_KEY));

        // 31 minutes after the last real request: signed out.
        $this->travel(11)->minutes();
        $this->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_tills_idle_sign_out_is_off_by_default_and_the_admins_is_30_minutes(): void
    {
        $this->assertSame(0, $this->shop->setting('till_idle_minutes'));
        $this->assertSame(30, $this->shop->setting('admin_idle_minutes'));

        $this->actingAs($this->cashier)->get(route('pos.index'))->assertInertia(fn ($page) => $page->where('idleTimeout', 0));
        $this->travel(12)->hours();
        $this->get(route('pos.index'))->assertOk();
        $this->assertAuthenticatedAs($this->cashier);

        $this->actingAs($this->owner)->get('/admin')->assertOk();
        $this->travel(31)->minutes();
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_the_till_has_its_own_idle_limit_and_zero_means_never(): void
    {
        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'admin_idle_minutes' => 30, 'till_idle_minutes' => 0]]);
        $this->actingAs($this->cashier);

        $this->get(route('pos.index'))->assertOk()->assertInertia(fn ($page) => $page->where('idleTimeout', 0));

        $this->travel(10)->hours();
        $this->get(route('pos.index'))->assertOk();
        $this->assertAuthenticatedAs($this->cashier);

        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'till_idle_minutes' => 120]]);
        $this->get(route('pos.index'))->assertInertia(fn ($page) => $page->where('idleTimeout', 7200));
    }

    public function test_a_livewire_poll_is_background_but_a_click_is_not(): void
    {
        $poll = request()->create('/livewire/update', 'POST', ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]]]]);
        $poll->headers->set('X-Livewire', '1');
        $click = request()->create('/livewire/update', 'POST', ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => 'save', 'params' => []]]]]]);
        $click->headers->set('X-Livewire', '1');

        $this->assertTrue(LogOutIdleUsers::isBackground($poll));
        $this->assertFalse(LogOutIdleUsers::isBackground($click));
    }

    public function test_security_headers_with_the_content_security_policy(): void
    {
        config(['pos.security.csp' => true]);

        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy());
        $this->assertStringContainsString("frame-ancestors 'self'", SecurityHeaders::contentSecurityPolicy());
        $this->assertStringContainsString("object-src 'none'", SecurityHeaders::contentSecurityPolicy());

        config(['pos.security.csp' => false]);
        $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_hsts_over_https_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_admin_ip_allowlist_keeps_the_panel_to_its_addresses_but_never_the_till(): void
    {
        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'admin_ip_allowlist' => ['203.0.113.0/24']]]);

        $this->actingAs($this->owner)->get('/admin')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/admin')->assertOk();
        // Through Caddy on the same server: the address it forwards counts, from it only.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/admin')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/admin')->assertForbidden();
        $this->actingAs($this->owner)->get(route('pos.index'))->assertOk();

        $this->artisan('pos:admin-ip', ['--clear' => true, '--shop' => 'DEMO'])->assertSuccessful();
        $this->get('/admin')->assertOk();
    }

    public function test_security_settings_save_and_refuse_a_list_that_locks_the_owner_out(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(SecuritySettings::class)
            ->fillForm(['admin_idle_minutes' => 15, 'till_idle_minutes' => 90, 'admin_ip_allowlist' => "203.0.113.7\n127.0.0.1", 'audit_log_retention_days' => 730])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->shop->refresh();
        $this->assertSame(15, $this->shop->setting('admin_idle_minutes'));
        $this->assertSame(90, $this->shop->setting('till_idle_minutes'));
        $this->assertSame(['203.0.113.7', '127.0.0.1'], $this->shop->setting('admin_ip_allowlist'));
        $this->assertSame(730, $this->shop->setting('audit_log_retention_days'));

        Livewire::test(SecuritySettings::class)
            ->fillForm(['admin_ip_allowlist' => '203.0.113.7'])
            ->call('save')
            ->assertHasErrors(['data.admin_ip_allowlist']);

        Livewire::test(SecuritySettings::class)
            ->fillForm(['admin_ip_allowlist' => 'not-an-ip'])
            ->call('save')
            ->assertHasErrors(['data.admin_ip_allowlist']);

        Livewire::test(SecuritySettings::class)
            ->fillForm(['admin_ip_allowlist' => '', 'audit_log_retention_days' => 30])
            ->call('save')
            ->assertHasErrors(['data.audit_log_retention_days']);

        $this->actingAs($this->cashier)->get(SecuritySettings::getUrl())->assertForbidden();
    }

    public function test_audit_prune_deletes_old_entries_but_keeps_cost_revaluations(): void
    {
        $make = fn (string $event, int $daysAgo): AuditLog => AuditLog::query()->forceCreate([
            'shop_id' => $this->shop->getKey(),
            'event' => $event,
            'created_at' => now()->subDays($daysAgo),
        ]);

        $old = $make('updated', 800);
        $revaluation = $make('cost_revalued', 800);
        $recent = $make('updated', 10);

        // Retention 0 = keep everything.
        $this->artisan('pos:audit-prune')->assertSuccessful();
        $this->assertModelExists($old);

        $this->shop->update(['settings' => [...$this->shop->settings ?? [], 'audit_log_retention_days' => 730]]);
        $this->artisan('pos:audit-prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($revaluation);
        $this->assertModelExists($recent);
    }

    /**
     * Strict passwords (as in production), with Have I Been Pwned faked (never called from a test):
     * the passwords in $breached are "known", everything else is not.
     *
     * @param  list<string>  $breached
     */
    private function production(array $breached = []): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => function (Request $request) use ($breached) {
            $prefix = substr((string) $request->url(), -5);
            $lines = [];

            foreach ($breached as $password) {
                $hash = strtoupper(sha1($password));

                if (str_starts_with($hash, strtoupper($prefix))) {
                    $lines[] = substr($hash, 5).':42';
                }
            }

            return Http::response(implode("\r\n", $lines));
        }]);
        config(['pos.security.strict_passwords' => true]);
    }

    private function passes(string $password, Password $rule): bool
    {
        return validator(['p' => $password], ['p' => $rule])->passes();
    }

    public function test_production_passwords_admin_users_need_12_characters_and_cashiers_10_never_a_breached_one(): void
    {
        $this->production(breached: ['tillcounter1', 'clinicowner2026']);

        // Admin users (admin.access): 12 or more, no rules on case or symbols.
        $this->assertFalse($this->passes('abcdefghij1', PasswordRules::forUser($this->owner)));
        $this->assertTrue($this->passes('quiet morning tea', PasswordRules::forUser($this->owner)));
        $this->assertFalse($this->passes('clinicowner2026', PasswordRules::forUser($this->owner)), 'Known from a breach.');

        // Till-only users: 10 or more.
        $this->assertFalse($this->passes('abcdefghi', PasswordRules::forUser($this->cashier)));
        $this->assertTrue($this->passes('bluecounter', PasswordRules::forUser($this->cashier)));
        $this->assertFalse($this->passes('tillcounter1', PasswordRules::forUser($this->cashier)), 'Known from a breach.');

        // Where the user is not known (Fortify's default): the admin rule.
        $this->app->getProvider(AppServiceProvider::class)?->boot();
        $this->assertFalse($this->passes('bluecounter', Password::defaults()));
    }

    public function test_production_user_form_takes_the_rule_of_the_role_chosen(): void
    {
        $this->production();
        $this->actingAs($this->owner);
        $user = fn (string $email, string $password, string $role): array => ['name' => 'New', 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true];

        Livewire::test(CreateUser::class)
            ->fillForm($user('till@pos.test', 'bluecounter', 'cashier'))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(User::query()->where('email', 'till@pos.test')->exists());

        Livewire::test(CreateUser::class)
            ->fillForm($user('admin@pos.test', 'bluecounter', 'owner'))
            ->call('create')
            ->assertHasFormErrors(['password']);

        // The hint under the field says the role's rule.
        $this->assertStringStartsWith('At least 10 characters', PasswordRules::describe(PasswordRules::roleIsAdmin('cashier')));
        $this->assertStringStartsWith('At least 12 characters', PasswordRules::describe(PasswordRules::roleIsAdmin('owner')));

        Livewire::test(CreateUser::class)
            ->fillForm($user('admin@pos.test', 'quiet morning tea', 'owner'))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_changing_your_own_password_takes_your_rule(): void
    {
        $this->production();
        $change = fn (string $password) => $this->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('user-password.update'), ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password]);

        $this->actingAs($this->cashier);
        $change('bluecounter')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('bluecounter', (string) $this->cashier->fresh()?->password));

        $this->actingAs($this->owner);
        $change('bluecounter')->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', (string) $this->owner->fresh()?->password));
    }
}
