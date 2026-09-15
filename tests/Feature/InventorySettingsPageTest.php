<?php

use App\Filament\Clusters\Settings\Pages\Couriers;
use App\Filament\Clusters\Settings\Pages\Inventory;
use App\Models\User;
use App\Support\Inventory\DaysOfCover;
use App\Support\Reporting\AttentionReason;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Settings\InventorySettings;
use Livewire\Livewire;

/**
 * The one number a Merchant gets to say about stock: how much warning they
 * want before a shelf runs out.
 *
 * What is proved here is that the page is reachable, gated with the rest of
 * Settings, and that what it saves is what the to-do list then draws its line
 * at. What Days of Cover itself says is `InventoryDaysOfCoverTest`'s and is
 * not measured again here.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('draws the page last in the Settings cluster, under the same permission as the rest', function () {
    $this->get(Inventory::getUrl())
        ->assertOk()
        ->assertSeeLivewire(Inventory::class);

    /** After Couriers at 4, which is the entry it was appended behind. */
    expect(Inventory::getNavigationSort())->toBeGreaterThan(Couriers::getNavigationSort());

    $this->actingAs(User::factory()->create())
        ->get(Inventory::getUrl())
        ->assertForbidden();
});

it('opens on the config default and saves a threshold of the Merchant\'s own', function () {
    Livewire::test(Inventory::class)
        ->assertSchemaStateSet(['thinCoverDays' => (int) config('giopages.inventory.thin_cover_days')])
        ->fillForm(['thinCoverDays' => 21])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(InventorySettings::class)->thinCoverDays)->toBe(21);
});

it('refuses a threshold under a day, which is the warning switched off rather than a number', function () {
    Livewire::test(Inventory::class)
        ->fillForm(['thinCoverDays' => 0])
        ->call('save')
        ->assertHasFormErrors(['thinCoverDays']);

    expect(app(InventorySettings::class)->thinCoverDays)->toBe((int) config('giopages.inventory.thin_cover_days'));
});

it('is the line the to-do list draws, and says what span the rate is read over', function () {
    $tea = shelfOf('Sylhet tea', 280);
    parcelShippedOn($tea, 28, reportingNow()->subDays(10));

    /** Two hundred and fifty-two days of cover: nobody's to act on under a seven-day threshold. */
    expect(thinCoverRowsAt())->toBe([]);

    Livewire::test(Inventory::class)
        ->assertSee('days of cover')
        ->assertSee('last '.DaysOfCover::SPAN_DAYS.' days')
        ->fillForm(['thinCoverDays' => 300])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(thinCoverRowsAt())->toBe(['Sylhet tea']);
});

/**
 * @return list<string>
 */
function thinCoverRowsAt(): array
{
    $rows = array_filter(
        app(ReportingQuery::class)->needsAttention(reportingNow())->rows,
        fn (NeedsAttentionRow $row): bool => $row->reason === AttentionReason::StockRunningOut,
    );

    return array_values(array_map(fn (NeedsAttentionRow $row): string => $row->name(), $rows));
}
