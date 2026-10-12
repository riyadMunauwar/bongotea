<?php

namespace App\Filament\Resources\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use App\Models\Page;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Authoring is a working area of its own, so somebody writing copy is never one
 * click from the Orders queue and the customer records in it.
 *
 * Two Permissions gate this resource, because deleting a Page is not the same
 * act as writing one (ADR-0026). `author pages` is the floor: the list and
 * the form sit behind `canAccess()`, and publishing or pulling a Page back
 * to draft is a save on the form, so it needs nothing finer. `delete pages`
 * is held back on top, because a Slug may be baked into links that outlive
 * the Page.
 *
 * That second gate lives on the Delete Actions themselves — in the table's
 * bulk bar (`PagesTable`) and the edit screen's header (`EditPage`) — each
 * authorized against `delete pages`, so a User without it sees neither drawn
 * rather than drawn and refused.
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

    /** Fourth, above Enquiries. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 3;

    /**
     * The whole resource — list and form — is behind
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
        ];
    }
}
