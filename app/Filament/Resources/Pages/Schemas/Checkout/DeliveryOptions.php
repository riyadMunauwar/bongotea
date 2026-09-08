<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Support\Checkout\CheckoutConfig;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

/**
 * What this Page charges to get a parcel to the door.
 */
class DeliveryOptions
{
    public static function offered(): Repeater
    {
        /**
         * In the merchant's own vocabulary rather
         * than in districts. Add, rename, reprice,
         * reorder and delete are all theirs: none of
         * these rows is a built-in the way a field is,
         * because nothing is waiting for a particular
         * one and a Page may perfectly well offer no
         * delivery at all.
         *
         * The order is the order the customer reads
         * them in, and the first is the one already
         * chosen when the form is drawn, so it belongs
         * at the top rather than sorted by price.
         *
         * The default is what puts a row on the create
         * screen, where there is no record to read one
         * from. It calls the same
         * `CheckoutConfig::default()` PageObserver
         * seeds with, so the two cannot disagree.
         */
        return Repeater::make('checkout_config.delivery_options')
            ->label('Delivery options')
            ->helperText('What delivery costs on this page. None charges nothing and shows no delivery row; one is added to the total with no question asked; two or more are offered to the customer as a choice, in this order.')
            ->default(self::defaults(...))
            ->addActionLabel('Add a delivery option')
            ->reorderable()
            ->columns(2)
            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                ? (string) $state['label']
                : null)
            /**
             * On the repeater rather than on the row,
             * because it is the only place that can see
             * every row at once. A duplicate name is
             * refused rather than silently deduplicated
             * on read: two identical radio buttons are
             * a choice that decides nothing, and the
             * label is what an Order snapshots.
             */
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $labels = array_map(
                        fn (mixed $option): string => is_array($option) ? trim((string) ($option['label'] ?? '')) : '',
                        array_values((array) $value),
                    );

                    /**
                     * Compared against the empty string
                     * rather than dropped by falsiness:
                     * "0" is a name a merchant may give
                     * an option, and a bare
                     * `array_filter` would take both
                     * copies of it out of the
                     * comparison and let the pair
                     * through. A blank one is left to
                     * the row's own `required`.
                     */
                    $labels = array_filter($labels, fn (string $label): bool => $label !== '');

                    if (count($labels) !== count(array_unique($labels))) {
                        $fail('Two delivery options cannot share a name. The name is what the customer picks and what the order records.');
                    }
                },
            ])
            ->schema([
                TextInput::make('label')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('What the customer picks, and what the order records — "Inside Dhaka", "Free".'),

                /**
                 * Typed and shown in major units, stored
                 * in minor ones like every other money
                 * value. Zero is a real answer: free
                 * delivery is an arrangement, not the
                 * absence of one.
                 */
                TextInput::make('amount')
                    ->label('Amount')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                    ->helperText('Added to the total, and collected at the door.')
                    ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                        ? null
                        : Money::toMajorString((int) $state))
                    ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((string) $state)),
            ]);
    }

    /**
     * The Delivery Option a new Page starts from, in the units this form types
     * in.
     *
     * Converted here rather than left to the amount field's `formatStateUsing`,
     * which a default never passes through — the row would arrive holding minor
     * units and be saved as a hundred times the charge. The same
     * `CheckoutConfig::default()` PageObserver seeds with, so the create screen
     * and a Page created anywhere else cannot disagree.
     *
     * @return list<array{label: string, amount: string}>
     */
    private static function defaults(): array
    {
        return array_map(
            fn (array $option): array => [
                'label' => $option['label'],
                'amount' => Money::toMajorString($option['amount']),
            ],
            CheckoutConfig::default()->deliveryOptionsToArray(),
        );
    }
}
