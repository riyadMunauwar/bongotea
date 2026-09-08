<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Enums\PaymentMethod;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Settings\PaymentSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
         * The methods the installation has switched on,
         * in the order the checkout will draw them,
         * reorderable by dragging — and, beside them,
         * anything this page already lists that has
         * since been switched off, so the list is kept
         * whole as stored: a method that stops being
         * usable stays where the merchant put it and
         * reappears when it is switched back on.
         *
         * A listed method that cannot be offered is
         * worded below the box rather than refused on
         * save, because the refusal is Settings' to
         * lift, not this page's. The checkout reads
         * the offered methods through the Config, which
         * drops what cannot be offered and falls back to
         * cash on delivery when nothing is left, so
         * neither this list nor a Settings change ever
         * leaves a live page with no way to order.
         */
        return Select::make('checkout_config.payment_methods')
            ->label('Payment methods')
            ->options(fn (Get $get): array => self::listable($get('checkout_config.payment_methods')))
            ->multiple()
            ->reorderable()
            /** So the wording below follows what is listed as it is listed, not at the next save. */
            ->live()
            /** What a new page starts with, from the same default PageObserver seeds with, so the two cannot disagree. */
            ->default(PaymentMethod::names(CheckoutConfig::default()->paymentMethods))
            ->rules(['array', Rule::in(array_keys(PaymentMethod::labels()))])
            ->helperText(fn (Get $get): string => self::help($get('checkout_config.payment_methods')));
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

    /**
     * What the box lets a merchant pick from: the installation's switched-on
     * methods, plus whatever this page already lists, keyed by name.
     *
     * @return array<string, string>
     */
    private static function listable(mixed $listed): array
    {
        $methods = app(PaymentSettings::class)->enabledMethods();

        foreach (self::listedMethods($listed) as $method) {
            if (! in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        $options = [];

        foreach ($methods as $method) {
            $options[$method->value] = $method->getLabel();
        }

        return $options;
    }

    /**
     * The line beneath the box, and beside it the refusal for each listed
     * method the installation cannot offer, in the words the Settings group
     * refuses it with — so the tab and the checkout cannot disagree about
     * what is drawn.
     */
    private static function help(mixed $listed): string
    {
        $settings = app(PaymentSettings::class);

        $refusals = [];

        foreach (self::listedMethods($listed) as $method) {
            $refusal = $settings->refusal($method);

            if ($refusal !== null) {
                $refusals[] = $refusal.' It is not drawn on this page until that changes.';
            }
        }

        return implode(' ', [
            'The ways a customer can pay on this page, in the order the checkout lists them; drag to reorder. Only methods switched on in Settings can be added. Leave the list empty and the page offers cash on delivery.',
            ...$refusals,
        ]);
    }

    /**
     * The methods the form's state lists, read totally: a name nothing knows
     * is skipped here as it is on read.
     *
     * @return list<PaymentMethod>
     */
    private static function listedMethods(mixed $listed): array
    {
        $methods = [];

        foreach ((array) $listed as $name) {
            $method = PaymentMethod::fromStored($name);

            if ($method !== null && ! in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }
}
