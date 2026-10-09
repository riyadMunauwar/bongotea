<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Enums\SystemPage;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'status' => PageStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => PageStatus::Published]);
    }

    /**
     * A published System Page at the slug the store opens with.
     */
    public function system(SystemPage $role): static
    {
        return $this->published()->state(fn (): array => [
            'title' => $role->getLabel(),
            'slug' => str_replace('_', '-', $role->value),
            'system_role' => $role,
        ]);
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    public function withBlocks(array $blocks): static
    {
        return $this->state(fn (): array => ['blocks' => $blocks]);
    }
}
