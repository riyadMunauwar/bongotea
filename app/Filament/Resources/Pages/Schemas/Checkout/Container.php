<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\Checkout\CheckoutAlignment;
use App\Support\Checkout\CheckoutContainer;
use App\Support\Checkout\CheckoutPadding;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;

/**
 * The Checkout's Container: the two boxes the island draws itself inside.
 *
 * The ground is the grey band the design paints behind everything, with the
 * padding around it; the card is the one bordered panel the Customer reads
 * inside it. This tab is where a Merchant dissolves the ground so the Checkout
 * sits flush inside a wrapper they drew themselves — the padding, width and
 * alignment controls the parent feature adds all hang off the toggle below.
 *
 * Deliberately its own tab beside Look rather than a twelfth Style Token: the
 * Look tab is the eleven tokens and nothing else, and turning the ground off is
 * structural rather than a colour or a corner. The controls are contributed one
 * at a time because a concern hands over one component and never a list;
 * `CheckoutTab` holds the order, with the toggle first as the cause the rest
 * are the effect of.
 */
class Container
{
    /**
     * Whether the Checkout draws its ground: the grey band behind the card and
     * the padding around it.
     *
     * The first control on the tab and the cause of everything below it: with
     * the ground on, the Checkout draws the band it always has; with it off,
     * the band and its padding are gone and the card sits flush against
     * whatever the Merchant wrapped the shortcode in. The card, and everything
     * a Customer reads inside it, is untouched either way.
     */
    public static function withContainer(): Toggle
    {
        return Toggle::make('checkout_config.container.enabled')
            ->label('Draw the checkout on its own ground')
            ->default(true)
            /**
             * Refused twice, like everything in a Config: worded here for
             * anything that is not a yes or a no, and dropped for the default
             * (the ground on) on read. A switch cannot type anything else, so
             * this is the refusal a Config written by another route meets.
             */
            ->rule('boolean')
            ->helperText('On, the checkout draws the grey ground behind the card, with padding around it, as designed. Off, that ground and its padding are removed and the card sits flush inside whatever you wrapped [checkout] in. The card itself is unchanged; recolour or clear the ground with the Background token on the Look tab.');
    }

    /**
     * The padding around the card, inside the ground.
     *
     * Two boxes rather than one, because top-and-bottom and side-to-side are
     * the design's own two figures and a Merchant flushing a Checkout into
     * their layout wants each on its own. Emitted only while the ground is
     * drawn, so a Page with the toggle off ignores whatever is typed here.
     */
    public static function groundPadding(): Fieldset
    {
        return Fieldset::make('Ground padding')
            ->columns(2)
            ->schema([
                self::length('checkout_config.container.ground.vertical', 'Top and bottom', CheckoutContainer::defaultGround()->vertical),
                self::length('checkout_config.container.ground.horizontal', 'Left and right', CheckoutContainer::defaultGround()->horizontal),
            ]);
    }

    /**
     * The padding inside the card, between its border and everything a Customer
     * reads.
     *
     * Set both to 0 for the flush recipe: with the ground off, the Surface
     * token transparent and the Border token transparent, the card dissolves
     * into the Merchant's own wrapper entirely.
     */
    public static function cardPadding(): Fieldset
    {
        return Fieldset::make('Card padding')
            ->columns(2)
            ->schema([
                self::length('checkout_config.container.card.vertical', 'Top and bottom', CheckoutContainer::defaultCard()->vertical),
                self::length('checkout_config.container.card.horizontal', 'Left and right', CheckoutContainer::defaultCard()->horizontal),
            ]);
    }

    /**
     * How wide the card sits and where it sits, as one row: a free `max-width`
     * and a left / centre / right placement.
     *
     * Both apply whether the ground is drawn or not, unlike the paddings above:
     * a Merchant flushing the Checkout into their own wrapper still wants to
     * narrow the card and place it, and a bare card centred at the design's
     * width is a box floating in the middle of a section they drew.
     */
    public static function cardWidthAndAlignment(): Fieldset
    {
        return Fieldset::make('Card width and alignment')
            ->columns(2)
            ->schema([
                self::width(),
                self::alignment(),
            ]);
    }

    /**
     * The card's width: a single bounded length, refused twice against the same
     * check the paddings use so the two cannot disagree about what a length is.
     *
     * Blank is never refused — an empty box is the design's `35rem`, which the
     * placeholder shows and the read fills in. `100%` fills the parent, which is
     * what a Merchant flushing the Checkout into their own narrow column reaches
     * for.
     */
    private static function width(): TextInput
    {
        return TextInput::make('checkout_config.container.width')
            ->label('Width')
            /** What is in force while the box is empty, so it reads without saving to find out — the same as a padding box. */
            ->placeholder(CheckoutContainer::DEFAULT_WIDTH)
            ->maxLength(CheckoutPadding::MAX_LENGTH)
            ->helperText('The most the card grows to: a number and a unit — px, rem, em, % or vw. Use 100% to fill whatever you wrapped [checkout] in.')
            ->rules([RefusedBy::rule(CheckoutPadding::refusal(...))]);
    }

    /**
     * Where the card sits inside its parent: left, centre or right, by an auto
     * margin on the side with the slack.
     *
     * Three fixed choices and no free value, the same trade a field's width
     * makes: a figure would resolve against a parent whose width nobody here
     * knows. Centre is the default the card has always been drawn at.
     */
    private static function alignment(): Select
    {
        return Select::make('checkout_config.container.alignment')
            ->label('Alignment')
            ->options(CheckoutAlignment::labels())
            ->default(CheckoutAlignment::Centre->value)
            ->selectablePlaceholder(false)
            ->helperText('Where the card sits when it is narrower than the space around it. At 100% width the three are the same.');
    }

    /**
     * One padding length: a number and a unit, refused twice.
     *
     * The refusal is the same one the Config makes as it reads a stored value,
     * asked of `CheckoutPadding` so the tab and the silent read cannot disagree
     * about what a padding is. Here it is worded and shown on the box that holds
     * it; there it is silent, because a Config written by some other route has
     * nobody to tell. Blank is never refused — an empty box is the design's
     * figure, which the placeholder shows and the read fills in.
     */
    private static function length(string $statePath, string $label, string $default): TextInput
    {
        return TextInput::make($statePath)
            ->label($label)
            /** What is in force while the box is empty, so it reads without saving to find out — the same as a Look token's box. */
            ->placeholder($default)
            ->maxLength(CheckoutPadding::MAX_LENGTH)
            ->helperText('A number and a unit: px, rem, em, % or vw. Use 0 for none.')
            ->rules([RefusedBy::rule(CheckoutPadding::refusal(...))]);
    }
}
