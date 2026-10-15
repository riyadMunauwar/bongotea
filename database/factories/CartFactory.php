<?php

namespace Database\Factories;

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Models\Cart;
use App\Models\Page;
use App\Support\Pages\Blocks\BulkOrderForm;
use App\Support\Pages\Blocks\PacketBasket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => OrderKind::Bulk,
            'is_switched_on' => true,
            'sort_order' => 0,
        ];
    }

    public function bulk(): static
    {
        return $this->state(['kind' => OrderKind::Bulk]);
    }

    public function retail(): static
    {
        return $this->state(['kind' => OrderKind::Retail]);
    }

    public function switchedOff(): static
    {
        return $this->state(['is_switched_on' => false]);
    }

    /**
     * Drawn by its kind's Block on a Page of its own, published unless told
     * otherwise — so, switched on, it is open.
     */
    public function onAPage(bool $published = true): static
    {
        return $this->afterCreating(function (Cart $cart) use ($published): void {
            $block = $cart->kind === OrderKind::Bulk ? BulkOrderForm::type() : PacketBasket::type();

            Page::factory()
                ->state(['status' => $published ? PageStatus::Published : PageStatus::Draft])
                ->withBlocks([['type' => $block, 'data' => ['cart_id' => $cart->getKey()]]])
                ->create();
        });
    }
}
