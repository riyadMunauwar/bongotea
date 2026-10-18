<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Pages;

use App\Filament\Clusters\Inventory\Resources\Supplies\Schemas\SupplyForm;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Models\Supplier;
use App\Support\Inventory\StockLedger;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Recording a delivery, which is how stock arrives and how cost enters the
 * system.
 *
 * The page fills a form and hands it to `StockLedger`; it writes nothing
 * itself. That is what keeps the document and the movements it caused
 * inseparable — a screen that saved the rows itself would be an invoice filed
 * against a shelf that never heard about it, and there would be no moment
 * between the two for anybody to forget.
 */
class CreateSupply extends CreateRecord
{
    protected static string $resource = SupplyResource::class;

    /** A shop owner adds a Purchase, so the page and its buttons say so (#172). */
    protected static ?string $title = 'Add purchase';

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save purchase');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Save & add another');
    }

    /**
     * The ledger writes the Supply, its lines and one movement per line, in
     * one transaction, and hands back the document Filament goes on to treat
     * as the created record.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $data['lines'];

        return app(StockLedger::class)->recordSupply(
            supplier: Supplier::findOrFail($data['supplier_id']),
            suppliedOn: $data['supplied_on'],

            /** Keyed by the repeater's own item keys, which the ledger has no use for; typed in kilograms or packets and recorded in grams at a cost per kilogram (ADR-0035). */
            lines: array_map(fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'pack_id' => blank($line['pack_id'] ?? null) ? null : (int) $line['pack_id'],
                'quantity' => SupplyForm::grams($line),
                'unit_cost' => SupplyForm::costPerKilogram($line, (int) $line['unit_cost']),
            ], array_values($lines)),

            reference: $data['reference'] ?? null,

            /** Who recorded it is taken from who is signed in, never from a field: an attributed figure nobody can choose the name on. */
            recordedBy: auth()->user(),
        );
    }

    /**
     * To the document just recorded, where the lines and what the ledger did
     * about them are read together. Not to the editor Filament would
     * ordinarily open, because there is none: a Supply is what it said when
     * it was recorded.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
