<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\CheckoutWords;
use App\Support\Checkout\OrderButton;
use App\Support\Checkout\WordSlot;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Everything the checkout says, in the merchant's own words, in one place.
 *
 * Every box is optional: an empty one is the merchant saying nothing, and the
 * checkout keeps the design's words, Bangla first and English second. Each box
 * shows those words as its placeholder, so what is in force reads without
 * saving to find out. A sentence is not a Style Token, which is why none of
 * this sits beside the colours.
 *
 * Three shared sections, then one per layout that owns words of its own,
 * contributed separately because a concern hands over one component at a time
 * and never a list; `CheckoutTab` is where their order lives. A layout's
 * section is shown only while that layout is chosen, and rides along unseen
 * otherwise, so a word written for a layout survives a move away and back.
 */
class Words
{
    public static function button(): Section
    {
        return Section::make('Button')
            ->description('What the button says and the line beneath it. Anything left empty keeps the checkout\'s own words.')
            ->columns(1)
            ->schema([
                TextInput::make('checkout_config.words.order_button.wording')
                    ->label('Button text')
                    /**
                     * The layout's own default, because several designs put
                     * the total on the button: the placeholder is the sentence
                     * the island will draw, following the layout card as it
                     * is chosen.
                     */
                    ->placeholder(fn (Get $get): string => LayoutChoice::selected($get)->defaultButtonWording())
                    ->helperText('Write '.OrderButton::TOTAL_PLACEHOLDER.' anywhere in it to put the live total on the button, e.g. "Order for '.OrderButton::TOTAL_PLACEHOLDER.'".')
                    ->rules([RefusedBy::rule(OrderButton::wordingRefusal(...))]),

                TextInput::make('checkout_config.words.order_button.note')
                    ->label('Line beneath the button')
                    ->placeholder(OrderButton::DEFAULT_NOTE)
                    ->helperText('A line of reassurance about how the customer pays.')
                    ->rules([RefusedBy::rule(OrderButton::noteRefusal(...))]),
            ]);
    }

    public static function totals(): Section
    {
        return Section::make('Totals')
            ->description('The three rows of the summary, in the order the customer reads them.')
            ->columns(3)
            ->schema([
                TextInput::make('checkout_config.words.subtotal')
                    ->label('Subtotal')
                    ->placeholder(CheckoutWords::DEFAULT_SUBTOTAL_LABEL)
                    ->rules([RefusedBy::rule(CheckoutWords::labelRefusal(...))]),

                TextInput::make('checkout_config.words.delivery')
                    ->label('Delivery')
                    ->placeholder(CheckoutWords::DEFAULT_DELIVERY_LABEL)
                    ->rules([RefusedBy::rule(CheckoutWords::labelRefusal(...))]),

                TextInput::make('checkout_config.words.total')
                    ->label('Total')
                    ->placeholder(CheckoutWords::DEFAULT_TOTAL_LABEL)
                    ->rules([RefusedBy::rule(CheckoutWords::labelRefusal(...))]),
            ]);
    }

    public static function payment(): Section
    {
        /**
         * One group per method the system knows, each shown only
         * while this page lists that method: a merchant is not
         * asked what to call a way of paying the page never offers.
         * The hidden groups ride along, so a label written for a
         * method survives every save made while it is off the page.
         */
        return Section::make('Payment')
            ->description('What each payment method is called: a long label for layouts with room and a short one for tight layouts. The layout decides which it draws.')
            ->columns(1)
            ->schema(array_map(self::labelsFor(...), PaymentMethod::cases()));
    }

    /**
     * The words one layout owns of its own, as a section shown only while
     * that layout is chosen.
     *
     * Hidden by the section and dehydrated-when-hidden on the section, not on
     * each box: `Component::isHidden()` never consults its parent, so the
     * boxes inside would carry no hint of their own, and a merchant switching
     * layouts and saving would silently lose the words they wrote for the one
     * they left — see `.ai/rules/schemas-order-complete.md`. Headed by the
     * layout's name, so the section says which arrangement is being worded.
     */
    public static function ownedBy(CheckoutLayout $layout): Section
    {
        return Section::make($layout->label())
            ->description('The words only the '.$layout->label().' layout draws. Anything left empty keeps the design\'s words.')
            ->columns(1)
            ->visible(fn (Get $get): bool => LayoutChoice::selected($get) === $layout)
            ->dehydratedWhenHidden()
            ->schema(array_map(self::boxFor(...), $layout->wordSlots()));
    }

    private static function boxFor(WordSlot $slot): TextInput
    {
        return TextInput::make('checkout_config.words.'.$slot->value)
            ->label($slot->label())
            ->placeholder($slot->defaultValue())
            ->rules([RefusedBy::rule($slot->refusal(...))]);
    }

    /**
     * The two boxes for one method, as a group the layout select can hide.
     *
     * Hidden by the group, and dehydrated-when-hidden on the group: a
     * `Component::isHidden()` never consults its parent, so the boxes inside
     * would carry no hint of their own — see `.ai/rules/schemas-order-complete.md`.
     */
    private static function labelsFor(PaymentMethod $method): Fieldset
    {
        return Fieldset::make($method->getLabel())
            ->columns(2)
            ->visible(fn (Get $get): bool => in_array($method, self::listed($get), true))
            ->dehydratedWhenHidden()
            ->schema([
                TextInput::make('checkout_config.words.payment.'.$method->value.'.long')
                    ->label('Long label')
                    ->placeholder(CheckoutWords::defaultPaymentLabel($method))
                    ->rules([RefusedBy::rule(CheckoutWords::paymentLabelRefusal(...))]),

                TextInput::make('checkout_config.words.payment.'.$method->value.'.short')
                    ->label('Short label')
                    ->placeholder(CheckoutWords::defaultShortPaymentLabel($method))
                    ->rules([RefusedBy::rule(CheckoutWords::shortPaymentLabelRefusal(...))]),
            ]);
    }

    /**
     * The methods the page lists as the tab has them now, read from the
     * Payment tab's list the way the Config reads it: an empty list offers
     * Cash on Delivery, so a page that says nothing is asked about that one.
     *
     * @return list<PaymentMethod>
     */
    private static function listed(Get $get): array
    {
        $listed = PaymentMethods::listedMethods($get('checkout_config.payment_methods'));

        return $listed === [] ? [PaymentMethod::CashOnDelivery] : $listed;
    }
}
