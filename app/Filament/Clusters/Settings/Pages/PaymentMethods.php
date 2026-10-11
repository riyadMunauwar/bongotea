<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\PaymentMethod;
use App\Support\Settings\PaymentSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
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
 * switch a gateway on and finish typing later, and the page words why it is
 * not yet offered.
 */
class PaymentMethods extends SettingsPage
{
    protected static string $group = PaymentSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    /** After the store's identity, announcement, Menus and Delivery Charges (1–4): how it is paid, then what it reports to ad platforms. */
    protected static ?int $navigationSort = 5;

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

            Section::make(PaymentMethod::Bkash->getLabel())
                ->description('bKash wallets through your bKash merchant account. Offered on a page only once it is switched on here and all four credentials are filled in. The hosts are the ones bKash gave you at onboarding.')
                ->columns(1)
                ->schema([
                    self::switch(PaymentMethod::Bkash),

                    TextInput::make('bkashAppKey')
                        ->label('App key')
                        ->maxLength(255)
                        ->helperText('Issued by bKash at onboarding, with the app secret.'),

                    TextInput::make('bkashAppSecret')
                        ->label('App secret')
                        ->maxLength(255),

                    TextInput::make('bkashUsername')
                        ->label('Username')
                        ->maxLength(255)
                        ->helperText('Your bKash merchant account username, used to obtain a token.'),

                    TextInput::make('bkashPassword')
                        ->label('Password')
                        ->maxLength(255),

                    TextInput::make('bkashSandboxHost')
                        ->label('Sandbox host')
                        ->url()
                        ->maxLength(255)
                        ->helperText('Up to and including the API version, as bKash gave it to you.'),

                    TextInput::make('bkashLiveHost')
                        ->label('Live host')
                        ->url()
                        ->maxLength(255),

                    self::sandbox(PaymentMethod::Bkash),
                    self::notificationUrl(PaymentMethod::Bkash),
                    self::logo(PaymentMethod::Bkash),
                ]),

            Section::make(PaymentMethod::AamarPay->getLabel())
                ->description('Cards, mobile banking and internet banking through your aamarPay store. Offered on a page only once it is switched on here and both credentials are filled in.')
                ->columns(1)
                ->schema([
                    self::switch(PaymentMethod::AamarPay),

                    TextInput::make('aamarpayStoreId')
                        ->label('Store id')
                        ->maxLength(255)
                        ->helperText('Issued by aamarPay with your store, beside the signature key.'),

                    TextInput::make('aamarpaySignatureKey')
                        ->label('Signature key')
                        ->maxLength(255),

                    self::sandbox(PaymentMethod::AamarPay),
                    self::notificationUrl(PaymentMethod::AamarPay),
                    self::logo(PaymentMethod::AamarPay),
                ]),

            Section::make(PaymentMethod::PayStation->getLabel())
                ->description('Cards, mobile wallets and internet banking through your PayStation merchant account. Offered on a page only once it is switched on here and both credentials are filled in.')
                ->columns(1)
                ->schema([
                    self::switch(PaymentMethod::PayStation),

                    TextInput::make('paystationMerchantId')
                        ->label('Merchant id')
                        ->maxLength(255)
                        ->helperText('Issued by PayStation with your merchant account, beside the password.'),

                    TextInput::make('paystationPassword')
                        ->label('Password')
                        ->maxLength(255),

                    self::sandbox(PaymentMethod::PayStation),
                    self::notificationUrl(PaymentMethod::PayStation),
                    self::logo(PaymentMethod::PayStation),
                ]),

            self::comingLater(),
        ];
    }

    /**
     * The methods still to come, named from the group's own shape: one with
     * no settings there yet. Drawn only while there is one, so a page with
     * every method's settings says nothing about later.
     */
    private static function comingLater(): Section
    {
        $labels = array_map(
            fn (PaymentMethod $method): string => $method->getLabel(),
            array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => ! PaymentSettings::hasSettings($method)),
        );

        return Section::make('Coming later')
            ->description('These arrive with their own settings and cannot be switched on yet.')
            ->columns(1)
            ->visible($labels !== [])
            ->schema([
                Text::make(implode(', ', $labels)),
            ]);
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
     * The Notification URL a gateway has registered once through its support
     * desk. Read-only and never saved: an entry, not a field, so it is drawn
     * whatever the form was filled with.
     */
    private static function notificationUrl(PaymentMethod $method): TextEntry
    {
        $label = $method->getLabel();

        return TextEntry::make(PaymentSettings::property($method, 'NotificationUrl'))
            ->label('Notification URL')
            ->state(fn (): string => route('payment.notify', ['method' => $method->value]))
            ->copyable()
            ->copyMessage('Copied')
            ->helperText("Hand this to {$label}'s support desk so they notify this site of every payment taken. {$label} does not take it with each payment.");
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
