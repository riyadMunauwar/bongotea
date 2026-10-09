<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\OrderText;
use App\Enums\SmsGateway;
use App\Support\PhoneNumber;
use App\Support\Settings\SmsSettings;
use App\Support\Sms\EnquiryAlerts;
use App\Support\Sms\OrderTexts;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * What the store sends by text message: through which SMS Gateway, each
 * Order Text and Staff Alert switched on or off and worded, and the
 * Merchant's own numbers the alerts go to.
 */
class Sms extends SettingsPage
{
    protected static string $group = SmsSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?string $navigationLabel = 'SMS';

    protected static ?string $title = 'SMS';

    /** Beside Couriers: the last of the companies the store pays to do something for it. */
    protected static ?int $navigationSort = 9;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('SMS Gateway')
                ->description('The company every text goes out through. Log only sends nothing and writes each text to the application log, which is what to use until a gateway is paid for.')
                ->columns(2)
                ->schema([
                    Select::make('gateway')
                        ->label('Send through')
                        /** By stored name rather than the enum, which Filament would hand back as a case where the group holds a string. */
                        ->options(collect(SmsGateway::cases())->mapWithKeys(fn (SmsGateway $gateway): array => [$gateway->value => $gateway->getLabel()]))
                        ->required()
                        ->native(false)
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('giosmsApiToken')
                        ->label('API token')
                        ->visible(fn (Get $get): bool => self::chose($get, SmsGateway::GioSms))
                        ->required(fn (Get $get): bool => self::chose($get, SmsGateway::GioSms) && ! app(SmsSettings::class)->hasSecret('giosmsApiToken'))
                        ->helperText('From GioSMS, under Settings → API Tokens.'),

                    TextInput::make('giosmsSenderId')
                        ->label('Sender id')
                        ->visible(fn (Get $get): bool => self::chose($get, SmsGateway::GioSms))
                        ->required(fn (Get $get): bool => self::chose($get, SmsGateway::GioSms))
                        ->maxLength(16)
                        ->helperText('The sender id registered with GioSMS, at most 16 characters.'),
                ]),

            Section::make('Order Texts')
                ->description('Sent to the Customer after each of these happens to their Order. '.self::placeholders())
                ->schema(array_merge(...array_map(
                    fn (OrderText $text): array => self::text($text->value, $text->getLabel()),
                    OrderText::cases(),
                ))),

            Section::make('Staff Alerts')
                ->description('Sent to the store\'s own numbers the moment a new Order is captured or a new Enquiry arrives, and never to a Customer.')
                ->schema([
                    TagsInput::make('staffNumbers')
                        ->label('Staff numbers')
                        ->placeholder('01700000000')
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            foreach ((array) $value as $number) {
                                if (! PhoneNumber::isValid((string) $number)) {
                                    $fail("[{$number}] is not a number this store can text, e.g. 01700000000.");
                                }
                            }
                        })
                        ->helperText('Every number the alert goes to. Press Enter after each.'),

                    ...self::text('newOrderAlert', 'New Order', self::placeholders()),

                    ...self::text('newEnquiryAlert', 'New Enquiry', 'The wording may use '.implode(', ', EnquiryAlerts::PLACEHOLDERS).'.'),
                ]),
        ];
    }

    /**
     * A switch and its wording, required while the switch is on.
     *
     * @return array{Toggle, Textarea}
     */
    private static function text(string $prefix, string $label, ?string $helperText = null): array
    {
        return [
            Toggle::make("{$prefix}Enabled")
                ->label($label)
                ->live(),

            Textarea::make("{$prefix}Template")
                ->hiddenLabel()
                ->rows(2)
                ->maxLength(1000)
                ->helperText($helperText)
                ->required(fn (Get $get): bool => (bool) $get("{$prefix}Enabled")),
        ];
    }

    private static function chose(Get $get, SmsGateway $gateway): bool
    {
        return $get('gateway') === $gateway->value;
    }

    private static function placeholders(): string
    {
        return 'The wording may use '.implode(', ', OrderTexts::PLACEHOLDERS).'.';
    }
}
