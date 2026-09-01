<?php

namespace Database\Factories;

use App\Enums\PageStatus;
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
            'body' => $this->document('<h1>'.e($title).'</h1>'),
            'status' => PageStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * A page whose body carries the given markup inside a complete document,
     * which is what a Page always stores.
     */
    public function withBody(string $markup): static
    {
        return $this->state(fn (): array => [
            'body' => $this->document($markup),
        ]);
    }

    private function document(string $markup): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Landing</title></head>
        <body>{$markup}</body>
        </html>
        HTML;
    }
}
