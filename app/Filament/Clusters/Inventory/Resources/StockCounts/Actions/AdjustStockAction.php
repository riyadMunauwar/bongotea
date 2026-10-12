<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Actions;

use App\Enums\AdjustmentCause;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Models\Product;
use App\Support\Inventory\AdjustmentBelowZero;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\StockNotCounted;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

use function Illuminate\Support\enum_value;

/**
 * The three verbs a Merchant reaches for on the Stock Adjustments screen:
 * **Set exact count**, **Increase (+)** and **Decrease (−)**, each opening the
 * same small dialog — Product, the number and the reason on one row, a note.
 *
 * Set exact count is the stocktake: the number on the shelf, handed to
 * `StockLedger::recordCount()`, which works the difference out. The everyday
 * case is not a stocktake, though — it is "two got dropped, take two off" and
 * "five turned up, add five". Increase and Decrease are the exact count doing
 * the arithmetic *for* them: each signs the typed magnitude by the button
 * pressed and hands it to `StockLedger::recordAdjustment()`. No action writes
 * anything itself — the ledger stays the single writer, and the whole
 * behaviour lives behind its one tested seam.
 *
 * Increase and Decrease show what is on hand the moment a Product is picked,
 * so a Decrease that would cross zero is seen before anything is saved. Set
 * exact count deliberately does not: a count is worth only what was arrived at
 * independently of the figure it is checked against.
 *
 * Nobody types a sign. The button carries the direction, so the quantity is
 * always a plain positive number; Increase passes a positive delta, Decrease a
 * negative one.
 *
 * The Reason is filtered and defaulted per direction, so a nonsensical record
 * cannot be made: an Increase offers only reasons that make sense for adding
 * (Found, Miscount), a Decrease only for removing (Damaged, Lost, Miscount),
 * and **Opening count** — the exact count's own default — is offered by
 * neither. A Select hands back its own default as the enum and a chosen option
 * as the string behind it, so `enum_value()` reads both.
 *
 * A Decrease is guarded twice over. The typed magnitude is checked against live
 * On Hand before submit — refused where the Product was never counted, or where
 * the decrease would cross below zero, with the Merchant nudged toward "Set
 * exact count" — and the ledger throws as the backstop for the concurrent Sale
 * that lands between the read and the write. An Increase needs no such guard: a
 * "+5" on a Product the ledger has never seen simply adopts it at five, the way
 * a first count does.
 *
 * Gated on `count stock` through the cluster, like every screen in here — an
 * Adjustment is worked out from a count rather than chosen, so counting and
 * adjusting are the same power and there is no separate permission.
 */
class AdjustStockAction
{
    /**
     * Write down the number on the shelf. The ledger records the count and
     * squares itself with it in one transaction; a first count adopts the
     * Product, which is why Opening count is the default here.
     */
    public static function exactCount(): Action
    {
        return self::base('count', 'Set exact count', Heroicon::OutlinedClipboardDocumentCheck)
            ->modalHeading('Set exact count')
            ->modalDescription('Write down the number you are holding, not the difference. The ledger works out the rest.')
            ->modalSubmitActionLabel('Save count')
            /**
             * The same dialog as the other two, less what the ledger holds: a
             * counter who can see the expected answer is a counter being
             * asked to agree with it, and the entire worth of a count is that
             * it was arrived at independently.
             */
            ->schema([
                self::product(),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('quantity')
                        ->label('Counted')
                        ->required()
                        ->integer()
                        ->minValue(0),
                    self::reason(AdjustmentCause::OpeningCount, self::labelled(...AdjustmentCause::cases())),
                ]),
                self::note(),
            ])
            ->action(function (array $data): void {
                app(StockLedger::class)->recordCount(
                    product: Product::findOrFail($data['product_id']),
                    counted: (int) $data['quantity'],
                    cause: AdjustmentCause::from(enum_value($data['cause'])),
                    note: $data['note'] ?? null,

                    /** Who counted is taken from who is signed in, never from a field: an attributed figure nobody can choose the name on. */
                    countedBy: auth()->user(),
                );

                Notification::make()
                    ->title('Count saved')
                    ->body('On hand now reads what you counted, and the difference is on the record with your name against it.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Add units to what is held. A positive delta, adopting an uncounted
     * Product at the quantity if the ledger has never seen it.
     */
    public static function increase(): Action
    {
        return self::base('increase', 'Increase (+)', Heroicon::OutlinedPlusCircle)
            ->modalHeading('Increase stock')
            ->modalDescription('Add units to what is on hand — five that turned up behind the counter. Type how many; the button carries the direction, so there is no sign to type.')
            ->modalSubmitActionLabel('Add to stock')
            ->schema(self::dialog(
                self::quantity(),
                self::reason(AdjustmentCause::Found, self::increaseReasons()),
            ))
            ->action(fn (array $data) => self::record($data, sign: 1));
    }

    /**
     * Take units off what is held. A negative delta, refused before submit and
     * again at the ledger where it would remove from an unknown or cross below
     * zero.
     */
    public static function decrease(): Action
    {
        return self::base('decrease', 'Decrease (−)', Heroicon::OutlinedMinusCircle)
            ->modalHeading('Decrease stock')
            ->modalDescription('Take units off what is on hand — two that were dropped and broken. Type how many; the button carries the direction, so there is no minus to type.')
            ->modalSubmitActionLabel('Remove from stock')
            ->schema(self::dialog(
                self::decreaseQuantity(),
                self::reason(AdjustmentCause::Damaged, self::decreaseReasons()),
            ))
            ->action(fn (array $data) => self::record($data, sign: -1));
    }

    /**
     * The shape all three share before their verb is put on: a header action
     * in the panel's own theme colour opening a medium dialog, gated on the
     * cluster's `count stock`.
     *
     * Every verb wears `primary` rather than a green/red pair: counting,
     * adding and removing stock are the same everyday admin act from three
     * sides, and the screen leads with the brand colour rather than a traffic
     * light.
     *
     * The gate sits on the action's own visibility as well as on the screen it
     * lives on, so a User without the Permission neither sees it nor can mount
     * it — the belt-and-braces `VoidSupplyAction` and the Order actions use.
     */
    private static function base(string $name, string $label, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('primary')
            ->modalWidth(Width::Medium)
            ->visible(fn (): bool => InventoryCluster::canAccess());
    }

    /**
     * The dialog Increase and Decrease open: the Product with what it holds
     * under it, the number and the reason side by side, and the note.
     *
     * @return array<int, Component>
     */
    private static function dialog(TextInput $quantity, Select $reason): array
    {
        return [
            self::product(),
            self::onHand(),
            Grid::make(['default' => 1, 'sm' => 2])->schema([$quantity, $reason]),
            self::note(),
        ];
    }

    /**
     * What the ledger says is on the shelf for the picked Product, read live,
     * so a Decrease that would cross zero is obvious before submit. One the
     * ledger has never seen says so rather than reading as nothing.
     */
    private static function onHand(): TextEntry
    {
        return TextEntry::make('on_hand')
            ->label('On hand now')
            ->inlineLabel()
            ->visible(fn (Get $get): bool => filled($get('product_id')))
            ->state(function (Get $get): string {
                $product = Product::withoutTrashed()->find($get('product_id'));
                $onHand = $product === null ? null : app(StockLedger::class)->onHand($product);

                return $onHand === null ? 'Not counted yet' : (string) $onHand;
            });
    }

    /**
     * Record the adjustment through the ledger, signing the typed magnitude by
     * the button pressed.
     *
     * The ledger's guards throw as the backstop for a race the pre-submit check
     * cannot see — a Sale landing between the On Hand it read and the write it
     * makes — and that is told about here rather than surfacing as a 500. Each
     * case keeps its own words, and both point at "Set exact count", which is
     * where a shelf genuinely out of step with the ledger is squared.
     *
     * @param  array<string, mixed>  $data
     */
    private static function record(array $data, int $sign): void
    {
        try {
            app(StockLedger::class)->recordAdjustment(
                product: Product::findOrFail($data['product_id']),
                delta: $sign * (int) $data['quantity'],

                /** `enum_value()` because the Select hands its own default back as the enum and a chosen option as the string behind it, exactly as the exact count reads it. */
                cause: AdjustmentCause::from(enum_value($data['cause'])),

                /** The optional note is the custom reason — anything the fixed list does not fit, in the Merchant's own words. */
                note: $data['note'] ?? null,

                /** Attributed to whoever is signed in, never to a field: an adjusted figure nobody can choose the name on. */
                adjustedBy: auth()->user(),
            );
        } catch (StockNotCounted|AdjustmentBelowZero $refused) {
            Notification::make()
                ->title('Nothing was changed')
                ->body($refused instanceof StockNotCounted
                    ? 'This product has not been counted, so there is no stock to remove. Count it with Set exact count first.'
                    : 'The shelf changed while this was open — there is not enough on hand to remove that many now. Count it with Set exact count instead.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Stock adjusted')
            ->body('On hand has moved by exactly what you typed, and the adjustment is on the record with your name against it.')
            ->success()
            ->send();
    }

    /**
     * The Product being counted or adjusted. Trashed ones are not offered: one
     * taken off the catalogue is not one anybody is standing in front of. Said
     * here rather than left to a relation, because a header action holds no
     * record to relate through. Live, so what it holds shows as it is picked.
     */
    private static function product(): Select
    {
        return Select::make('product_id')
            ->label('Product')
            ->options(fn (): array => Product::query()->withoutTrashed()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->live()
            ->required();
    }

    /**
     * The magnitude, always a plain positive whole number. The button says the
     * direction, so a sign is neither typed nor accepted.
     */
    private static function quantity(): TextInput
    {
        return TextInput::make('quantity')
            ->label('Quantity')
            ->helperText('How many units to move — a plain positive number.')
            ->required()
            ->integer()
            ->minValue(1);
    }

    /**
     * The Decrease magnitude, checked against live On Hand before submit so an
     * over-decrease is caught while the Merchant is still looking at the dialog
     * rather than as an exception on click.
     *
     * Refused two ways, the same two the ledger refuses: removing from a Product
     * the ledger has never seen (there is no figure to take from), and taking
     * what is held below nothing (a figure the ledger may conclude but no
     * Merchant may assert by hand). Both nudge toward "Set exact count", which
     * is where a shelf genuinely emptier than the ledger thinks is squared.
     */
    private static function decreaseQuantity(): TextInput
    {
        return self::quantity()
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $productId = $get('product_id');

                    /** The Product's and the quantity's own required/integer rules speak first; this only adds the shelf check. */
                    if (blank($productId) || ! is_numeric($value)) {
                        return;
                    }

                    $product = Product::withoutTrashed()->find($productId);

                    if ($product === null) {
                        return;
                    }

                    $onHand = app(StockLedger::class)->onHand($product);

                    if ($onHand === null) {
                        $fail('You have not counted this product yet, so there is no stock to remove. Increase it or Set exact count first.');

                        return;
                    }

                    if ($onHand - (int) $value < 0) {
                        $fail("Only {$onHand} on hand, so removing ".(int) $value.' would take it below zero. Set an exact count instead.');
                    }
                },
            ]);
    }

    /**
     * The Reason, filtered and defaulted per verb. The default is the enum
     * instance, so an untouched Select hands it back as the enum for
     * `enum_value()` to read.
     *
     * @param  array<string, string>  $options
     */
    private static function reason(AdjustmentCause $default, array $options): Select
    {
        return Select::make('cause')
            ->label('Reason')
            ->options($options)
            ->default($default)
            ->required();
    }

    /**
     * The optional free-text note, carried through as the custom reason.
     */
    private static function note(): Textarea
    {
        return Textarea::make('note')
            ->label('Note')
            ->helperText('Optional — anything the reasons above do not cover, in your own words. It stays with the adjustment.')
            ->rows(2);
    }

    /**
     * The reasons that make sense for adding, Found first as the default.
     *
     * @return array<string, string>
     */
    private static function increaseReasons(): array
    {
        return self::labelled(AdjustmentCause::Found, AdjustmentCause::Miscount);
    }

    /**
     * The reasons that make sense for removing, Damaged first as the default.
     *
     * @return array<string, string>
     */
    private static function decreaseReasons(): array
    {
        return self::labelled(AdjustmentCause::Damaged, AdjustmentCause::Lost, AdjustmentCause::Miscount);
    }

    /**
     * A subset of the fixed `AdjustmentCause` list as a Select's options, keyed
     * by the value behind each so a chosen one arrives as that string.
     *
     * @return array<string, string>
     */
    private static function labelled(AdjustmentCause ...$causes): array
    {
        $options = [];

        foreach ($causes as $cause) {
            $options[$cause->value] = $cause->getLabel();
        }

        return $options;
    }
}
