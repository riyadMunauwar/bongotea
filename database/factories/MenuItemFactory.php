<?php

namespace Database\Factories;

use App\Enums\MenuLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A top-level link to a typed address unless a state says otherwise.
 *
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'parent_id' => null,
            'position' => 0,
            'label' => fake()->word(),
            'type' => MenuLinkType::Url,
            'note' => null,
            'linkable_type' => null,
            'linkable_id' => null,
            'anchor' => null,
            'url' => '/'.fake()->slug(2),
        ];
    }

    public function under(MenuItem $parent): static
    {
        return $this->state(fn (): array => ['menu_id' => $parent->menu_id, 'parent_id' => $parent->id]);
    }

    public function page(Page $page): static
    {
        return $this->state(fn (): array => [
            'type' => MenuLinkType::Page,
            'linkable_type' => $page->getMorphClass(),
            'linkable_id' => $page->id,
            'url' => null,
        ]);
    }

    public function anchor(string $anchor, ?Page $page = null): static
    {
        return $this->state(fn (): array => [
            'type' => MenuLinkType::Anchor,
            'linkable_type' => $page?->getMorphClass(),
            'linkable_id' => $page?->id,
            'anchor' => $anchor,
            'url' => null,
        ]);
    }

    public function heading(): static
    {
        return $this->state(fn (): array => ['type' => MenuLinkType::Heading, 'url' => null]);
    }
}
