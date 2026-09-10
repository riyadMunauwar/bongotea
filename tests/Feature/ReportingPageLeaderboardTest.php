<?php

use App\Enums\OrderStatus;
use App\Models\Page;
use App\Support\Reporting\PageLeaderboard;
use App\Support\Reporting\PageLeaderboardRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Carbon\CarbonImmutable;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function leaderboardOf(ReportingPreset $preset = ReportingPreset::Last7Days): PageLeaderboard
{
    return app(ReportingQuery::class)->pageLeaderboard(ReportingWindow::of($preset, reportingNow()));
}

/**
 * @return list<string>
 */
function leaderboardTitles(PageLeaderboard $leaderboard): array
{
    return array_map(fn (PageLeaderboardRow $row): string => $row->title(), $leaderboard->rows);
}

it('lists one row per Page with Orders in the window, Sales first, with the glossary\'s figures', function () {
    $first = Page::factory()->published()->create(['title' => 'Summer combo', 'published_at' => CarbonImmutable::parse('2026-08-20 09:00', 'Asia/Dhaka')]);
    $second = Page::factory()->create(['title' => 'Winter combo']);
    Page::factory()->create(['title' => 'Never sold']);

    orderCapturedAt('2026-09-05 12:00', 50000, ['page_id' => $first->id]);
    orderCapturedAt('2026-09-06 12:00', 30000, ['page_id' => $first->id], OrderStatus::Confirmed);
    orderCapturedAt('2026-09-07 12:00', 80000, ['page_id' => $first->id], OrderStatus::Cancelled);
    orderCapturedAt('2026-09-07 12:00', 90000, ['page_id' => $first->id])->delete();
    orderCapturedAt('2026-09-08 12:00', 200000, ['page_id' => $second->id]);
    orderCapturedAt('2026-09-02 23:59', 500000, ['page_id' => $second->id]);

    $leaderboard = leaderboardOf();

    expect(leaderboardTitles($leaderboard))->toBe(['Winter combo', 'Summer combo']);

    $row = $leaderboard->rows[1];

    expect($row->page?->is($first))->toBeTrue()
        ->and($row->isDeleted())->toBeFalse()
        ->and($row->wentLiveAt())->toEqual($first->published_at)
        ->and($row->orders)->toBe(3)
        ->and($row->cancelled)->toBe(1)
        ->and($row->sales)->toBe(56000 + 36000)
        ->and($row->averageOrderValue())->toBe(46000)
        ->and($row->cancellationRate())->toBe(1 / 3)
        ->and($leaderboard->rows[0]->wentLiveAt())->toBeNull();
});

it('keeps a soft-deleted Page on the board, marked deleted, with its Page still attached', function () {
    $page = Page::factory()->published()->create(['title' => 'Retired combo']);
    orderCapturedAt('2026-09-05 12:00', 50000, ['page_id' => $page->id]);
    $page->delete();

    $row = leaderboardOf()->rows[0];

    expect($row->title())->toBe('Retired combo')
        ->and($row->isDeleted())->toBeTrue()
        ->and($row->page?->is($page))->toBeTrue()
        ->and($row->sales)->toBe(56000);
});

it('groups Orders whose Page was deleted for good under the recorded slug, with no Page to link to', function () {
    $page = Page::factory()->create(['title' => 'Gone combo', 'slug' => 'gone-combo']);
    orderCapturedAt('2026-09-05 12:00', 50000, ['page_id' => $page->id]);
    orderCapturedAt('2026-09-06 12:00', 30000, ['page_id' => $page->id], OrderStatus::Cancelled);
    orderCapturedAt('2026-09-06 13:00', 10000, ['page_id' => null, 'page_slug' => 'another-gone']);
    $page->forceDelete();

    $leaderboard = leaderboardOf();

    expect(leaderboardTitles($leaderboard))->toBe(['gone-combo', 'another-gone']);

    $row = $leaderboard->rows[0];

    expect($row->page)->toBeNull()
        ->and($row->isDeleted())->toBeFalse()
        ->and($row->wentLiveAt())->toBeNull()
        ->and($row->orders)->toBe(2)
        ->and($row->cancelled)->toBe(1)
        ->and($row->sales)->toBe(56000);
});

it('is empty on a window with nothing captured', function () {
    Page::factory()->published()->create();
    orderCapturedAt('2026-09-02 12:00');

    expect(leaderboardOf()->rows)->toBe([]);
});
