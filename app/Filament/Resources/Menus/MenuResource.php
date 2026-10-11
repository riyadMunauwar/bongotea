<?php

namespace App\Filament\Resources\Menus;

use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use App\Filament\Resources\Menus\Tables\MenusTable;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Menu;
use App\Support\Menus\MenuIsAssigned;
use App\Support\Menus\MenuPlaces;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Named Menus, built in a drag-and-drop builder and assigned to places
 * (ADR-0033).
 *
 * Storefront content rather than configuration, so it sits under Pages in
 * the sidebar — the way Categories sit under Products — and is gated with
 * Pages on `author pages`. Creating asks only for a name and lands on the
 * builder; editing is the builder.
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?string $navigationParentItem = 'Pages';

    /** Under Pages: Header 0, Footer 1, Menus 2. */
    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return PageResource::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return MenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenusTable::configure($table);
    }

    /**
     * A delete that a place would be left blank by is refused before it is
     * tried, naming the places, rather than reaching the model's own refusal
     * as an error page.
     */
    public static function refuseDeletingAssigned(DeleteAction $action): DeleteAction
    {
        return $action->before(function (DeleteAction $action, Menu $record): void {
            $places = MenuPlaces::of($record);

            if ($places === []) {
                return;
            }

            Notification::make()
                ->danger()
                ->title('Menu not deleted')
                ->body(MenuIsAssigned::to($record, $places)->getMessage())
                ->send();

            $action->cancel();
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'create' => CreateMenu::route('/create'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
