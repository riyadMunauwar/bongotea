<?php

namespace App\Filament\Resources\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Pages\PageReport;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use App\Models\Page;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Authoring is a working area of its own, so somebody writing copy is never one
 * click from the Orders queue and the customer records in it.
 *
 * Two Permissions gate this resource, because binning a Page is not the same
 * act as writing one (ADR-0026). `author pages` is the floor: the list, the
 * form, the preview and the Page Report all sit behind `canAccess()`, and
 * publishing or pulling a Page back to draft is a save on the form, so it
 * needs nothing finer. `delete pages` is held back on top, because a Slug is
 * claimed for good and may be baked into ad creatives that outlive the Page,
 * and deleting one can also unseat the Front Page — neither is something the
 * person writing headlines should be able to spend.
 *
 * That second gate lives on the Delete, Force Delete and Restore Actions
 * themselves — in the table's bulk bar (`PagesTable`) and the edit screen's
 * header (`EditPage`) — each authorized against `delete pages`, so a User
 * without it sees none of the three drawn rather than drawn and refused.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    /**
     * Ours, not Heroicons'. `AdminPanelProvider::boot()` registers the set and
     * carries the reasoning: a funnel is what a Merchant arriving from
     * CartFlows reads as "the surface that makes money", and Filament's own
     * funnel is already the filter button on the very screen this opens.
     */
    protected static string|BackedEnum|null $navigationIcon = 'giopages-funnel';

    /** Fifth, above Reports. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 4;

    /**
     * The whole resource — list, form, preview and Page Report — is behind
     * `author pages`. Closing the resource's own URL too, so a User without it
     * gets a forbidden response rather than a screen they were never meant to
     * reach, and Filament hides the sidebar entry from them.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::AuthorPages) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
            'report' => PageReport::route('/{record}/report'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
