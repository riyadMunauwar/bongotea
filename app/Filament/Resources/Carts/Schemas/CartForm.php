<?php

namespace App\Filament\Resources\Carts\Schemas;

use App\Enums\OrderKind;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Products a Cart offers on the left, picked one by one or a Category at
 * a time; beside it, the Cart's name, kind, switch and place in the order.
 *
 * Only Products sold the Cart's way are offered, so the list waits on the
 * kind, and choosing another kind on a new Cart clears what was ticked.
 */
class CartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    Section::make('Products')
                        ->description('What this Cart\'s page offers. A Customer may still add any tea sold this way.')
                        ->schema([
                            /**
                             * A shortcut that writes Product ticks and is not
                             * itself saved: a tea added to the Category later
                             * joins no Cart until it is ticked itself.
                             */
                            Select::make('category_pick')
                                ->label('Tick a Category\'s Products')
                                ->helperText('Choose the Cart\'s kind first. Ticks every Product the Category holds now; untick any you don\'t want.')
                                ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                ->searchable()
                                ->disabled(fn (Get $get): bool => self::kind($get('kind')) === null)
                                ->dehydrated(false)
                                ->live()
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    $kind = self::kind($get('kind'));

                                    if (blank($state) || $kind === null) {
                                        return;
                                    }

                                    $ticked = Product::query()
                                        ->where('category_id', $state)
                                        ->soldAs($kind)
                                        ->pluck('id');

                                    $set('products', collect($get('products') ?? [])
                                        ->merge($ticked)
                                        ->map(fn (int|string $id): string => (string) $id)
                                        ->unique()
                                        ->values()
                                        ->all());

                                    $set('category_pick', null);
                                }),

                            CheckboxList::make('products')
                                ->hiddenLabel()
                                ->relationship(
                                    'products',
                                    'name',
                                    fn (Builder $query, Get $get): Builder => ($kind = self::kind($get('kind')))
                                        ? $query->soldAs($kind)
                                        : $query->whereKey([]),
                                )
                                ->searchable()
                                ->bulkToggleable()
                                ->columns(2)
                                ->noSearchResultsMessage('No Product sold this way matches.'),
                        ]),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    Section::make('Cart')
                        ->schema([
                            TextInput::make('name')
                                ->helperText('Shown to Customers as you type it.')
                                ->required()
                                ->maxLength(255),

                            Radio::make('kind')
                                ->options(OrderKind::class)
                                ->helperText('Fixed once the Cart is made.')
                                ->required()
                                ->live()
                                ->disabledOn('edit')
                                ->afterStateUpdated(fn (Set $set) => $set('products', [])),

                            Toggle::make('is_switched_on')
                                ->label('Switched on')
                                ->helperText('A switched-off Cart is offered to no one, and its Customers\' Contents are kept for when it reopens.')
                                ->default(true),

                            TextInput::make('sort_order')
                                ->label('Sort order')
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    /**
     * The form's kind as a case, whether the record hydrated it as one or a
     * Radio left its value; null until one is chosen.
     */
    private static function kind(mixed $state): ?OrderKind
    {
        return $state instanceof OrderKind ? $state : OrderKind::tryFrom((string) $state);
    }
}
