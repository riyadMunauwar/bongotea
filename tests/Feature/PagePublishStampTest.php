<?php

use App\Enums\PageStatus;
use App\Models\Page;

it('stamps the publish time when a page first goes live', function () {
    $page = Page::factory()->create();

    expect($page->published_at)->toBeNull();

    $page->update(['status' => PageStatus::Published]);

    expect($page->fresh()->published_at)->not->toBeNull();
});

it('keeps the first publish time when a page is republished', function () {
    $firstWentLive = now()->subDays(4);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => $firstWentLive,
    ]);

    $page->update(['status' => PageStatus::Draft]);
    $page->update(['status' => PageStatus::Published]);

    expect($page->fresh()->published_at->toDateTimeString())
        ->toBe($firstWentLive->toDateTimeString());
});

it('leaves a draft without a publish time', function () {
    $page = Page::factory()->create();

    $page->update(['title' => 'Renamed']);

    expect($page->fresh()->published_at)->toBeNull();
});
