<?php

namespace Tests\Feature\Pos;

use Inertia\Testing\AssertableInertia;

/**
 * "/" and the sign-in page in the retail shop: the same redirects as the
 * clinic, and its own name with the plain mark (no medical cross).
 */
class RetailHomeTest extends PosTestCase
{
    public function test_home_sends_a_guest_to_sign_in_and_anyone_signed_in_home(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get('/')->assertRedirect('/admin');
        $this->actingAs($this->cashier)->get('/')->assertRedirect('/pos');
    }

    public function test_the_sign_in_page_is_the_shops_with_the_plain_mark(): void
    {
        $html = (string) $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brand.name', $this->shop->name)
                ->where('brand.tagline', 'Point of sale & stock')
                ->where('brand.clinic', false))
            ->getContent();

        $this->assertStringContainsString('<link rel="icon" href="/favicon.svg">', $html);
        $this->assertStringNotContainsString('data-pos-theme', $html);
    }
}
