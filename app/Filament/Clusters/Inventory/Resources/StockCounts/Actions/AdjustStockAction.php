<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Actions;

use App\Enums\AdjustmentDirection;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Models\AdjustmentCause;
use App\Models\Pack;
use App\Models\Product;
use App\Support\Inventory\AdjustmentBelowZero;
use App\Support\Inventory\AdjustmentCauseRefused;
use App\Support\Inventory\Grams;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\StockNotCounted;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * **Adjust stock**: the one form a Merchant uses whenever a shelf and the
 * ledger disagree (ADR-0040).
 *
 * Top to bottom, one question a line, each explained under it: which tea,
 * what happened (**Add**, **Remove**, or **I counted it**), in which unit (a
 * packet size of that tea, or kilograms), how many, and why. What On Hand
 * will read after saving is shown before they save.
 *
 * A Pack holds no stock of its own (ADR-0035): the unit only says what one of
 * the typed quantity weighs, and the grams come off the tea's one stock. A
 * Product nobody has counted offers only I counted it — there is nothing to
 * add to or take from — and records an opening count; a count that agrees
 * records a check. Otherwise the reason picker offers the current causes that
 * go the way the change went, and adds a new one from its own + button.
 *
 * Worked out against the shelf at the moment of saving: an Add or Remove is
 * applied as a change, a count is squared as a total. No action writes
 * anything itself: `StockLedger::recordCount()` and `recordAdjustment()` are
 * the single writer, and refuse what this form should already have stopped.
 *
 * Gated on `count stock` through the cluster, like every screen in here.
 */
class AdjustStockAction
{
    /** The unit for tea in no packet, or a shelf of mixed packet sizes. */
    private const KILOGRAMS = 'kg';

    /**
     * The header action on Stock Adjustments, and the row action on the
     * Products list — where the Product is the row's, shown but locked.
     */
    public static function make(): Action
    {
        return Action::make('adjust')
            ->label('Adjust stock')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('primary')
            ->modalHeading('Adjust stock')
            ->modalDescription('Fix a tea\'s stock when it does not match what is on the shelf.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth(Width::Large)
            ->visible(fn (): bool => InventoryCluster::canAccess())
            ->fillForm(function (?Model $record): array {
                if (! $record instanceof Product) {
                    return [];
                }

                return [
                    'product_id' => $record->getKey(),
                    'what' => self::held($record) === null ? 'count' : null,
                    'unit' => self::defaultUnit($record),
                ];
            })
            ->schema([
                self::product(),
                self::onHand(),
                self::what(),
                self::unit(),
                self::quantity(),
                self::after(),
                self::cause(),
                self::checked(),
                self::note(),
            ])
            ->action(function (array $data, Action $action, ?Model $record): void {
                $product = $record instanceof Product ? $record : Product::findOrFail($data['product_id']);

                self::record($product, $data, $action);
            });
    }

    /**
     * Write it through the ledger by what happened.
     *
     * The ledger's refusals are the backstop for a shelf that moved while the
     * form was open — a parcel booked, another Merchant's adjustment — and are
     * told about here, with the form left open to look again, rather than
     * surfacing as a 500.
     *
     * @param  array<string, mixed>  $data
     */
    private static function record(Product $product, array $data, Action $action): void
    {
        $ledger = app(StockLedger::class);
        $cause = filled($data['cause'] ?? null) ? AdjustmentCause::find($data['cause']) : null;
        $grams = self::grams($product, $data['unit'], $data['quantity']);

        try {
            if ($data['what'] === 'count') {
                $ledger->recordCount(
                    product: $product,
                    counted: $grams,
                    cause: $cause,
                    note: $data['note'] ?? null,

                    /** Attributed to whoever is signed in, never to a field: a figure nobody can choose the name on. */
                    countedBy: auth()->user(),
                );
            } else {
                $ledger->recordAdjustment(
                    product: $product,
                    delta: $data['what'] === 'add' ? $grams : -$grams,
                    cause: $cause,
                    note: $data['note'] ?? null,
                    adjustedBy: auth()->user(),
                );
            }
        } catch (StockNotCounted|AdjustmentBelowZero|AdjustmentCauseRefused $refused) {
            Notification::make()
                ->title('Nothing was changed')
                ->body($cause?->isRetired() && $refused instanceof AdjustmentCauseRefused
                    ? 'That reason was retired while this was open. Pick another and save again.'
                    : 'The stock changed while this was open. Check what is on hand now and save again.')
                ->warning()
                ->send();

            $action->halt();
        }

        Notification::make()
            ->title('Stock adjusted')
            ->body('On hand now reads what you saved, with your name against it.')
            ->success()
            ->send();
    }

    /**
     * The tea. Trashed ones are not offered: one taken off the catalogue is
     * not one anybody is standing in front of.
     */
    private static function product(): Select
    {
        return Select::make('product_id')
            ->label('Product')
            ->helperText('The tea whose stock is wrong.')
            ->options(fn (): array => Product::query()->withoutTrashed()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->live()
            ->required()
            ->disabled(fn (?Model $record): bool => $record instanceof Product)
            /** Another tea has other packet sizes and may never have been counted, so the form starts over for it. */
            ->afterStateUpdated(function (?string $state, Set $set): void {
                $product = blank($state) ? null : Product::withoutTrashed()->find($state);

                $set('what', $product !== null && self::held($product) === null ? 'count' : null);
                $set('unit', $product === null ? null : self::defaultUnit($product));
                $set('quantity', null);
            });
    }

    /**
     * What the ledger holds for the chosen tea, read live — in the chosen
     * packet size too, where it makes a whole number of them.
     */
    private static function onHand(): TextEntry
    {
        return TextEntry::make('on_hand')
            ->label('On hand now')
            ->inlineLabel()
            ->weight('bold')
            ->visible(fn (Get $get, ?Model $record): bool => self::chosen($get, $record) !== null)
            ->state(function (Get $get, ?Model $record): string {
                $product = self::chosen($get, $record);
                $onHand = self::held($product);

                if ($onHand === null) {
                    return 'Not counted yet';
                }

                $pack = self::pack($product, $get('unit'));
                $packets = $pack === null ? null : Grams::packetsIn($onHand, $pack->grams);

                return Grams::format($onHand).($packets === null ? '' : " ({$packets} × ".self::packName($pack).')');
            });
    }

    /**
     * What happened, each choice saying what it does. A tea nobody has
     * counted can only be counted: there is nothing to add to or take from.
     */
    private static function what(): Radio
    {
        return Radio::make('what')
            ->label('What happened?')
            ->options(fn (Get $get, ?Model $record): array => self::isCounted($get, $record)
                ? ['add' => 'Add +', 'remove' => 'Remove −', 'count' => 'I counted it =']
                : ['count' => 'I counted it ='])
            ->descriptions(fn (Get $get, ?Model $record): array => self::isCounted($get, $record)
                ? [
                    'add' => 'Stock came in or was found. Adds to what is on hand.',
                    'remove' => 'Stock was damaged, lost, tasted or used. Takes it off.',
                    'count' => 'You counted the shelf. On hand becomes exactly this.',
                ]
                : ['count' => 'No stock is recorded for this tea yet. Enter how much you have now.'])
            ->visible(fn (Get $get, ?Model $record): bool => self::chosen($get, $record) !== null)
            ->live()
            ->required();
    }

    /**
     * What one of the quantity weighs: a packet size of this tea, or
     * kilograms.
     */
    private static function unit(): Select
    {
        return Select::make('unit')
            ->label('Count in')
            ->helperText('Which packet size you are counting. Choose Kilograms for tea that is not packed, or for a shelf of mixed sizes.')
            ->options(function (Get $get, ?Model $record): array {
                $product = self::chosen($get, $record);

                if ($product === null) {
                    return [];
                }

                return $product->packs
                    ->mapWithKeys(fn (Pack $pack): array => [$pack->getKey() => self::packName($pack).' packets'])
                    ->put(self::KILOGRAMS, 'Kilograms')
                    ->all();
            })
            ->visible(fn (Get $get, ?Model $record): bool => self::chosen($get, $record) !== null)
            ->live()
            ->required();
    }

    /**
     * How many: whole packets, or kilograms to the gram. Carries the refusals
     * that are about the number — nothing to add or remove, and removing more
     * than is held, which is a figure the ledger may conclude but no Merchant
     * may assert by hand.
     */
    private static function quantity(): TextInput
    {
        return TextInput::make('quantity')
            ->label(fn (Get $get): string => match ($get('what')) {
                'add' => 'How many to add',
                'remove' => 'How many to remove',
                default => 'How many you have now',
            })
            ->suffix(fn (Get $get): string => $get('unit') === self::KILOGRAMS ? 'kg' : 'packets')
            ->numeric()
            ->minValue(0)
            ->step(fn (Get $get): string => $get('unit') === self::KILOGRAMS ? '0.001' : '1')
            ->rule(fn (Get $get): string => $get('unit') === self::KILOGRAMS ? 'decimal:0,3' : 'integer')
            ->visible(fn (Get $get, ?Model $record): bool => self::chosen($get, $record) !== null)
            ->live(onBlur: true)
            ->required()
            ->rules([fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                $product = self::chosen($get, $record);

                if ($product === null || ! is_numeric($value) || ! in_array($get('what'), ['add', 'remove'], true)) {
                    return;
                }

                $grams = self::grams($product, $get('unit'), $value);

                if ($grams <= 0) {
                    $fail('Enter how many to '.$get('what').'.');

                    return;
                }

                $onHand = self::held($product) ?? 0;

                if ($get('what') === 'remove' && $onHand - $grams < 0) {
                    $fail('Only '.Grams::format($onHand).' on hand, so removing '.Grams::format($grams).' would take it below zero.');
                }
            }]);
    }

    /**
     * What On Hand will read after saving, and how far it moves — so the
     * Merchant sees the result before they commit to it.
     */
    private static function after(): TextEntry
    {
        return TextEntry::make('after')
            ->label('After saving')
            ->inlineLabel()
            ->weight('bold')
            ->visible(fn (Get $get, ?Model $record): bool => self::difference($get, $record) !== null)
            ->state(function (Get $get, ?Model $record): string {
                $onHand = self::held(self::chosen($get, $record));
                $difference = self::difference($get, $record);
                $after = Grams::format(($onHand ?? 0) + $difference);

                if ($onHand === null) {
                    return $after;
                }

                $moved = match (true) {
                    $difference > 0 => '+'.Grams::format($difference),
                    $difference < 0 => '−'.Grams::format(-$difference),
                    default => 'no change',
                };

                return "{$after} (was ".Grams::format($onHand).", {$moved})";
            });
    }

    /**
     * The reason, asked for only where the figure moves on a tea already
     * counted: the current causes going the way the change went, and a + to
     * add one going that way too.
     */
    private static function cause(): Select
    {
        return Select::make('cause')
            ->label('Reason')
            ->helperText('Why the stock changed. Missing one? Add it with +.')
            ->options(function (Get $get, ?Model $record): array {
                $difference = self::difference($get, $record) ?? 0;

                return AdjustmentCause::query()
                    ->current()
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (AdjustmentCause $cause): bool => $cause->fits($difference))
                    ->mapWithKeys(fn (AdjustmentCause $cause): array => [$cause->getKey() => $cause->name])
                    ->all();
            })
            ->visible(fn (Get $get, ?Model $record): bool => self::held(self::chosen($get, $record)) !== null
                && ! in_array(self::difference($get, $record), [null, 0], true))
            ->required()
            ->createOptionForm(fn (Get $get, ?Model $record): array => [
                TextInput::make('name')
                    ->label('Reason')
                    ->required()
                    ->maxLength(100)
                    ->unique(AdjustmentCause::class, 'name'),
                ToggleButtons::make('direction')
                    ->label('Used when stock is')
                    ->options(AdjustmentDirection::class)
                    ->inline()
                    ->required()
                    ->default((self::difference($get, $record) ?? 0) > 0 ? AdjustmentDirection::Adds : AdjustmentDirection::Removes),
            ])
            ->createOptionUsing(fn (array $data): int => AdjustmentCause::create($data)->getKey());
    }

    /**
     * Where a count agrees with the books there is nothing to explain: said
     * plainly where the reason would otherwise be asked for.
     */
    private static function checked(): TextEntry
    {
        return TextEntry::make('checked')
            ->hiddenLabel()
            ->state('✓ Matches the books. This check will be recorded.')
            ->color('success')
            ->visible(fn (Get $get, ?Model $record): bool => self::difference($get, $record) === 0
                && self::held(self::chosen($get, $record)) !== null);
    }

    private static function note(): Textarea
    {
        return Textarea::make('note')
            ->label('Note')
            ->helperText('Optional. Anything worth remembering later, in your own words.')
            ->rows(2);
    }

    private static function chosen(Get $get, ?Model $record): ?Product
    {
        if ($record instanceof Product) {
            return $record;
        }

        return blank($get('product_id')) ? null : Product::withoutTrashed()->with('packs')->find($get('product_id'));
    }

    private static function held(?Product $product): ?int
    {
        return $product === null ? null : app(StockLedger::class)->onHand($product);
    }

    private static function isCounted(Get $get, ?Model $record): bool
    {
        return self::held(self::chosen($get, $record)) !== null;
    }

    /**
     * The packet size a quantity is counted in, or null for kilograms.
     */
    private static function pack(Product $product, mixed $unit): ?Pack
    {
        return $unit === self::KILOGRAMS || blank($unit) ? null : $product->packs->firstWhere('id', (int) $unit);
    }

    /**
     * The tea's first packet size, which is how it is usually counted, or
     * kilograms for a tea sold in none.
     */
    private static function defaultUnit(Product $product): int|string
    {
        return $product->packs->first()?->getKey() ?? self::KILOGRAMS;
    }

    /**
     * A quantity in its unit, as the grams the ledger counts.
     */
    private static function grams(Product $product, mixed $unit, mixed $quantity): int
    {
        $pack = self::pack($product, $unit);

        return $pack === null
            ? Grams::fromKilograms($quantity)
            : Grams::ofPackets((int) $quantity, $pack->grams);
    }

    /**
     * How far On Hand would move, or null until the form says enough to tell.
     */
    private static function difference(Get $get, ?Model $record): ?int
    {
        $product = self::chosen($get, $record);

        if ($product === null || blank($get('what')) || blank($get('unit')) || ! is_numeric($get('quantity'))) {
            return null;
        }

        $grams = self::grams($product, $get('unit'), $get('quantity'));

        return match ($get('what')) {
            'add' => $grams,
            'remove' => -$grams,
            default => $grams - (self::held($product) ?? 0),
        };
    }

    private static function packName(Pack $pack): string
    {
        return $pack->grams >= Grams::PER_KILOGRAM && $pack->grams % Grams::PER_KILOGRAM === 0
            ? ($pack->grams / Grams::PER_KILOGRAM).' kg'
            : "{$pack->grams} g";
    }
}
