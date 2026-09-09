<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\Checkout\CheckoutWords;
use App\Support\Checkout\OrderButton;
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
 * Three sections, contributed separately because a concern hands over one
 * component at a time and never a list; `CheckoutTab` is where their order
 * lives. A layout's own slots — a numbered heading, a step label — are added
 * by the layout that draws them, as a section shown only while it is chosen.
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
                    ->placeholder(OrderButton::DEFAULT_WORDING)
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
