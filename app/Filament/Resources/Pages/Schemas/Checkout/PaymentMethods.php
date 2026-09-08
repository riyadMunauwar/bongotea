<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Enums\PaymentMethod;
use App\Support\Checkout\CheckoutConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Validation\Rule;

/**
 * How a customer may pay on this Page, and how the choice is drawn.
 *
 * Per Page rather than per installation, because a merchant wants this per
 * campaign: one Page selling a low-ticket item wants cash on delivery only
 * with no choice drawn, another wants bKash first with logos. The credentials
 * that make a method usable are the installation's, and live in Settings.
 *
 * The controls are contributed separately because a concern hands over one
 * component at a time and never a list; `CheckoutTab` is where their order
 * lives.
 */
class PaymentMethods
{
    public static function offered(): Select
    {
        /**
         * Every method the system knows by name, in
         * the order the checkout will draw them,
         * reorderable by dragging. Only cash on
         * delivery can be picked yet: the online
         * methods are listed so the merchant can see
         * what is coming, and held shut because a
         * method with no driver cannot take money.
         *
         * The list is kept whole as stored — a method
         * that stops being usable stays where the
         * merchant put it and reappears when it is
         * switched back on. The checkout reads the
         * offered methods through the Config, which
         * drops what cannot be offered and falls back
         * to cash on delivery when nothing is left,
         * so emptying this list never leaves a live
         * page with no way to order.
         */
        return Select::make('checkout_config.payment_methods')
            ->label('Payment methods')
            ->options(PaymentMethod::labels())
            ->multiple()
            ->reorderable()
            ->disableOptionWhen(fn (string $value): bool => ! PaymentMethod::from($value)->isUsable())
            /** What a new page starts with, from the same default PageObserver seeds with, so the two cannot disagree. */
            ->default(PaymentMethod::names(CheckoutConfig::default()->paymentMethods))
            ->rules(['array', Rule::in(array_keys(PaymentMethod::labels()))])
            ->helperText('The ways a customer can pay on this page, in the order the checkout lists them; drag to reorder. Only cash on delivery can be offered yet — the online methods arrive with their own settings. Leave the list empty and the page offers cash on delivery.');
    }

    public static function logos(): Toggle
    {
        /**
         * On, so a page that starts offering a second
         * method looks right with no upload from the
         * merchant. A minimal page switches it off and
         * lists methods by name alone.
         */
        return Toggle::make('checkout_config.show_payment_logos')
            ->label('Show logos')
            ->default(true)
            ->helperText('Draw each method\'s logo beside its name in the checkout.');
    }

    public static function loneCash(): Toggle
    {
        /**
         * On, so a page that has always been cash-only
         * draws the same form it always did: a chooser
         * with one row is a decision the customer
         * cannot make. Off, the checkout draws the one
         * row so the customer sees how they will pay.
         */
        return Toggle::make('checkout_config.hide_lone_cash_choice')
            ->label('Hide the choice when only cash on delivery is offered')
            ->default(true)
            ->helperText('On, a page offering nothing but cash on delivery asks no question about payment. Off, it draws the single cash on delivery row so the customer sees how they will pay.');
    }
}
