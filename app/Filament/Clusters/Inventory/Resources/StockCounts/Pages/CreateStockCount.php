<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Enums\AdjustmentCause;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use App\Models\Product;
use App\Support\Inventory\StockLedger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

use function Illuminate\Support\enum_value;

/**
 * Counting something, which is the only way anything enters the ledger today.
 *
 * The page fills a form and hands it to `StockLedger`; it writes nothing
 * itself. That is what keeps the Adjustment and the count inseparable — a
 * screen that saved the record itself would be a count on the record with a
 * figure that never heard about it, and a second screen doing the same later
 * would be a second answer to what the ledger holds.
 */
class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    /**
     * The ledger records the count and squares itself with it, in one
     * transaction, and hands back the row Filament goes on to treat as the
     * created record.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(StockLedger::class)->recordCount(
            product: Product::findOrFail($data['product_id']),
            counted: (int) $data['quantity'],

            /** `enum_value()` because a Select hands back its own default as the enum and a chosen option as the string behind it. */
            cause: AdjustmentCause::from(enum_value($data['cause'])),
            note: $data['note'] ?? null,

            /** Who counted is taken from who is signed in, never from a field: an attributed figure nobody can choose the name on. */
            countedBy: auth()->user(),
        );
    }

    /**
     * Back to the list, where the count just taken is the first row and the
     * ledger's answer to it is beside it. There is no record page to go to:
     * a count cannot be edited, so the screen that would have opened is the
     * one place a User could do nothing at all.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
