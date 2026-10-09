<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * Call, WhatsApp and email as three cards. The numbers and the address are
 * the Store settings', drawn from the shared props, so they are typed once;
 * the Block holds only the line under each.
 */
class ContactCards extends PageBlock
{
    public static function type(): string
    {
        return 'contact_cards';
    }

    public static function label(): string
    {
        return 'Contact cards';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedPhone;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('whatsapp_note')
                ->label('Under WhatsApp')
                ->placeholder('সাধারণত ১০ মিনিটে উত্তর')
                ->maxLength(120),
            TextInput::make('email_note')
                ->label('Under email')
                ->placeholder('২৪ ঘণ্টার মধ্যে উত্তর')
                ->maxLength(120),
            Toggle::make('raised')
                ->label('Lift over the Block above')
                ->helperText('As the Contact page lifts its cards over its banner.'),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'whatsappNote' => self::text($data, 'whatsapp_note'),
            'emailNote' => self::text($data, 'email_note'),
            'raised' => (bool) ($data['raised'] ?? false),
        ];
    }
}
