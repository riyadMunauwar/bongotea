<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\PaymentMethod;
use App\Support\Settings\PaymentSettings;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * Where a Merchant switches each Payment Method on for the installation,
 * types what its gateway needs, picks sandbox or live, and replaces its logo.
 *
 * One section per method, in the enum's order. The credentials are plain
 * `TextInput`s here: the page base reads the group's secrets list and makes
 * every one of them write-only, sealed at rest and rotated-only in the log
 * (ADR-0012). Nothing on this page says "secret".
 *
 * A switched-on gateway with a blank credential is offered nowhere — the
 * group's rule, not this page's — so nothing here is required: a Merchant may
 * switch a gateway on and finish typing later, and the Checkout tab words why
 * it is not yet drawn.
 */
class PaymentMethods extends SettingsPage
{
    protected static string $group = PaymentSettings::class;

    protected static ?int $navigationSort = 1;

    /**
     * @return array<Component>
     */
    protected function formComponents(): array
    {
        return [
            $this->currencyWarning(),

            Section::make(PaymentMethod::CashOnDelivery->getLabel())
                ->description('The courier collects the total at the door. Every page starts with this, and falls back to it when nothing else it lists can be offered.')
                ->columns(1)
                ->schema([
                    self::switch(PaymentMethod::CashOnDelivery),
                    self::logo(PaymentMethod::CashOnDelivery),
                ]),

            Section::make(PaymentMethod::SslCommerz->getLabel())
                ->description('Cards, mobile banking and internet banking through your SSLCommerz store. Offered on a page only once it is switched on here and both credentials are filled in.')
                ->columns(1)
                ->schema([
                    self::switch(PaymentMethod::SslCommerz),

                    TextInput::make('sslcommerzStoreId')
                        ->label('Store id')
                        ->maxLength(255)
                        ->helperText('Issued by SSLCommerz with your store.'),

                    TextInput::make('sslcommerzStorePassword')
                        ->label('Store password')
                        ->maxLength(255),

                    self::sandbox(PaymentMethod::SslCommerz),
                    self::logo(PaymentMethod::SslCommerz),
                ]),

            /** Named from the group's own shape: a method with no settings there yet is one still to come. */
            Section::make('Coming later')
                ->description('These arrive with their own settings and cannot be switched on yet.')
                ->columns(1)
                ->schema([
                    Text::make(implode(', ', array_map(
                        fn (PaymentMethod $method): string => $method->getLabel(),
                        array_values(array_filter(
                            PaymentMethod::cases(),
                            fn (PaymentMethod $method): bool => ! PaymentSettings::hasSettings($method),
                        )),
                    ))),
                ]),
        ];
    }

    /**
     * Drawn only while the installation currency is not the one the online
     * methods take, so a Merchant filling in a gateway learns here why no page
     * will draw it, rather than from a chooser that never appears. Read inside
     * closures, so it follows the General page as it stands when the form is
     * drawn rather than when the schema was built.
     */
    private function currencyWarning(): Text
    {
        return Text::make(fn (): string => sprintf(
            'The currency is %s. Every online payment method takes %s only, so none of them can be offered on any page until the currency on the General page is %s. Cash on Delivery is unaffected.',
            app(PaymentSettings::class)->currencyCode(),
            PaymentSettings::CURRENCY,
            PaymentSettings::CURRENCY,
        ))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->visible(fn (): bool => ! app(PaymentSettings::class)->currencyIsSupported());
    }

    private static function switch(PaymentMethod $method): Toggle
    {
        return Toggle::make(PaymentSettings::property($method, 'Enabled'))
            ->label('Offer '.$method->getLabel())
            ->helperText('Off, and no page offers it whatever its checkout lists.');
    }

    /**
     * Sandbox by default, so a Merchant who switches a gateway on and pastes
     * credentials from the wrong tab moves no real money until they say so.
     */
    private static function sandbox(PaymentMethod $method): Toggle
    {
        return Toggle::make(PaymentSettings::property($method, 'Sandbox'))
            ->label('Sandbox')
            ->helperText('On, payments go to the gateway\'s sandbox and no real money moves. Switch off to go live.');
    }

    /**
     * A replacement for the logo the checkout draws beside the method's name.
     * Written to the public local disk the way a Product's image is
     * (ADR-0011); the row keeps only the path, and blank draws the default.
     */
    private static function logo(PaymentMethod $method): FileUpload
    {
        return FileUpload::make(PaymentSettings::property($method, 'Logo'))
            ->label('Logo')
            ->disk(PaymentSettings::LOGO_DISK)
            ->directory(PaymentSettings::LOGO_DIRECTORY)
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
            ->maxSize(512)
            ->imagePreviewHeight('60')
            ->helperText('Replaces the default logo on every checkout that shows logos. Leave empty to keep the default.');
    }
}
