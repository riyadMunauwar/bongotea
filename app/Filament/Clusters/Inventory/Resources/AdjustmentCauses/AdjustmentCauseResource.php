<?php

namespace App\Filament\Clusters\Inventory\Resources\AdjustmentCauses;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Pages\ManageAdjustmentCauses;
use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Schemas\AdjustmentCauseForm;
use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Tables\AdjustmentCausesTable;
use App\Models\AdjustmentCause;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The Merchant's own list of reasons a shelf was out (ADR-0040).
 *
 * New ones are mostly added from the Adjust stock dialog's own +; this is
 * where a typo is put right, a reason nobody uses any more is retired, and a
 * reason added by mistake is deleted while nothing carries it yet.
 */
class AdjustmentCauseResource extends Resource
{
    protected static ?string $model = AdjustmentCause::class;

    /** What the Merchant calls them; the domain word is cause. */
    protected static ?string $navigationLabel = 'Adjustment Reasons';

    protected static ?string $modelLabel = 'Adjustment Reason';

    protected static ?string $pluralModelLabel = 'Adjustment Reasons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = InventoryCluster::class;

    /** After Stock Counts 0, Supplies 1, Suppliers 2: reference the work points at, last. */
    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AdjustmentCauseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdjustmentCausesTable::configure($table);
    }

    /** Counting and the reasons a count gives are the same `count stock` power. */
    public static function canAccess(): bool
    {
        return InventoryCluster::canAccess();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdjustmentCauses::route('/'),
        ];
    }
}
