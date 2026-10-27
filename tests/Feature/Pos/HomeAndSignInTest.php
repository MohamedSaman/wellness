<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * "/" and the sign-in page in the clinic: "/" is never a page of its own (a
 * guest signs in, anyone signed in goes where signing in takes them), and
 * the sign-in page, the tab and the icon are the shop's, never Laravel's.
 */
class HomeAndSignInTest extends PharmacyTestCase
{
    public function test_a_guest_on_the_home_page_is_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_someone_signed_in_goes_where_signing_in_takes_them(): void
    {
        $this->actingAs($this->owner)->get('/')->assertRedirect('/admin');
        $this->actingAs($this->cashier)->get('/')->assertRedirect('/pos');

        // Neither the panel nor the till: the clinic's welcome.
        $nobody = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $this->actingAs($nobody)->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_sign_in_page_is_the_shops(): void
    {
        // As production serves it: no SSR bundle is built or run there, so the page is rendered in the
        // browser, which makes the title "Sign in - <shop>" (e2e login-page.spec.ts). Off here too, so
        // a Vite dev server running on this machine can't render it on the server instead.
        config(['inertia.ssr.enabled' => false]);

        $html = (string) $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/Login')
                ->where('name', $this->shop->name)
                ->where('brand', [
                    'name' => $this->shop->name,
                    'logoUrl' => null,
                    'tagline' => 'Pharmacy & OPD system',
                    'clinic' => true,
                ]))
            ->getContent();

        $this->assertStringContainsString('<title>'.e($this->shop->name).'</title>', $html);
        $this->assertStringContainsString('<link rel="icon" href="/favicon-clinic.svg">', $html);

        foreach (['laravel.com', 'laracasts', 'Deploy now', 'cloud.laravel'] as $starter) {
            $this->assertStringNotContainsStringIgnoringCase($starter, $html);
        }
    }

    public function test_the_shops_logo_shows_on_the_sign_in_page_and_is_the_icon_without_a_favicon(): void
    {
        Storage::fake('public');
        $this->shop->forceFill(['logo_path' => 'branding/logo.png'])->save();

        $html = (string) $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('brand.logoUrl', Storage::disk('public')->url('branding/logo.png')))
            ->getContent();
        $this->assertStringContainsString('<link rel="icon" href="'.Storage::disk('public')->url('branding/logo.png').'">', $html);

        // A favicon of its own wins.
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'favicon_path' => 'branding/favicon.png']]);
        $this->assertStringContainsString(
            '<link rel="icon" href="'.Storage::disk('public')->url('branding/favicon.png').'">',
            (string) $this->get(route('login'))->getContent(),
        );
    }

    public function test_the_admin_panel_wears_the_shops_name_and_icon(): void
    {
        Filament::setCurrentPanel('admin');

        $html = (string) $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Dashboard\s*-\s*'.preg_quote(e($this->shop->name), '/').'\s*<\/title>/', $html);
        $this->assertStringContainsString('href="/favicon-clinic.svg"', $html);
    }
}
