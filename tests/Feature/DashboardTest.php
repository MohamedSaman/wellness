<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without a shop role get a welcome with nothing to open', function () {
    $user = User::factory()->create(['name' => 'Nimal Perera']);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('firstName', 'Nimal')
            ->where('canUsePos', false));
});
