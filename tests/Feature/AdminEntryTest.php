<?php

use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

/**
 * Rendering an admin screen over HTTP leaves Livewire's "a component rendered
 * this request" flag set in static state, and Livewire injects its assets into
 * any HTML response while that flag stands. Left behind, it would follow this
 * file into the next test and land script tags in a Page that is served byte
 * for byte.
 */
afterEach(function (): void {
    Livewire::flushState();
});

it('opens onto the orders queue rather than a dashboard', function () {
    $this->actingAs(User::factory()->create());

    /**
     * The queue is the work. There is nothing a dashboard could greet a User
     * with that the first screen of their day should be spending itself on.
     */
    $this->get('/admin')->assertRedirect('/admin/orders');
});

it('lands a signed-in user on the queue itself from the bare panel url', function () {
    $this->actingAs(User::factory()->create());

    $order = Order::factory()->create(['customer_name' => 'Rahim Uddin']);

    $this->followingRedirects()
        ->get('/admin')
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('Rahim Uddin');
});

it('sends a signed-out User to the login screen', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});
