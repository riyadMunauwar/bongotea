<?php

use App\Enums\AdjustmentDirection;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\AdjustmentCauseResource;
use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Pages\ManageAdjustmentCauses;
use App\Models\AdjustmentCause;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * The Merchant's own list of reasons a shelf was out (ADR-0040): renamed in
 * place, retired rather than deleted once anything carries one.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->countingStock()->create());

    $this->ledger = app(StockLedger::class);
});

it('sits in the inventory cluster after the other three, gated on count stock', function () {
    expect(AdjustmentCauseResource::getCluster())->toBe(InventoryCluster::class)
        ->and(AdjustmentCauseResource::getNavigationSort())->toBe(3);

    Livewire::test(ManageAdjustmentCauses::class)
        ->assertCanSeeTableRecords(AdjustmentCause::all())
        ->assertSeeText('Sample / tasting');

    $this->actingAs(User::factory()->create());
    $this->get(AdjustmentCauseResource::getUrl('index'))->assertForbidden();
});

it('adds a cause going one way', function () {
    Livewire::test(ManageAdjustmentCauses::class)
        ->callAction('create', ['name' => 'Packet torn', 'direction' => AdjustmentDirection::Removes->value])
        ->assertHasNoActionErrors();

    expect(AdjustmentCause::query()->where('name', 'Packet torn')->sole()->direction)->toBe(AdjustmentDirection::Removes);
});

it('renames a cause on every adjustment that already carries it', function () {
    $typo = AdjustmentCause::factory()->create(['name' => 'Damgaed', 'direction' => AdjustmentDirection::Removes]);
    $product = Product::factory()->create();
    $this->ledger->recordCount($product, 10000);
    $count = $this->ledger->recordCount($product, 9000, $typo);

    Livewire::test(ManageAdjustmentCauses::class)
        ->callAction(TestAction::make('edit')->table($typo), ['name' => 'Packet torn'])
        ->assertHasNoActionErrors();

    expect($count->refresh()->adjustmentCause->name)->toBe('Packet torn');
});

it('retires a used cause instead of deleting it, and keeps it on what it explains', function () {
    $lost = cause('Lost');
    $product = Product::factory()->create();
    $this->ledger->recordCount($product, 10000);
    $count = $this->ledger->recordCount($product, 9000, $lost);

    Livewire::test(ManageAdjustmentCauses::class)
        /** Deleting it would leave a year of Adjustments explaining nothing. */
        ->assertActionHidden(TestAction::make('delete')->table($lost))
        ->callAction(TestAction::make('retire')->table($lost))
        ->assertActionHidden(TestAction::make('retire')->table($lost))
        ->assertActionVisible(TestAction::make('reinstate')->table($lost));

    expect($lost->refresh()->isRetired())->toBeTrue()
        ->and(AdjustmentCause::query()->current()->pluck('name'))->not->toContain('Lost')
        ->and($count->refresh()->adjustmentCause->name)->toBe('Lost');

    Livewire::test(ManageAdjustmentCauses::class)
        ->callAction(TestAction::make('reinstate')->table($lost));

    expect($lost->refresh()->isRetired())->toBeFalse();
});

it('deletes a cause nothing carries', function () {
    $mistake = AdjustmentCause::factory()->create();

    Livewire::test(ManageAdjustmentCauses::class)
        ->callAction(TestAction::make('delete')->table($mistake));

    expect($mistake->fresh())->toBeNull();
});
