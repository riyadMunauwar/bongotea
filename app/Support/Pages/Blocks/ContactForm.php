<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A live Block: the contact form — a name, a phone number, a topic chosen
 * from the Merchant's chips and a message — whose question becomes an
 * Enquiry for a User to call back. The Merchant writes the wording and the
 * topics; the buyer's answer goes to the `enquiry` link, never through the
 * Page.
 */
class ContactForm extends PageBlock
{
    public static function type(): string
    {
        return 'contact_form';
    }

    public static function label(): string
    {
        return 'Contact form';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedChatBubbleBottomCenterText;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::ContactAndAccount;
    }

    public static function description(): string
    {
        return 'A form whose question becomes an Enquiry for someone to call back.';
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),

            Repeater::make('topics')
                ->helperText('The chips a buyer picks what their question is about from. The first is chosen to begin with.')
                ->simple(TextInput::make('label')->required()->maxLength(60))
                ->maxItems(8),
        ];
    }

    public static function props(array $data): array
    {
        $topics = is_array($data['topics'] ?? null) ? $data['topics'] : [];

        return [
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'topics' => array_values(array_unique(array_filter(array_map(
                fn (mixed $topic): ?string => is_string($topic) && trim($topic) !== '' ? mb_substr(trim($topic), 0, 60) : null,
                array_values($topics),
            )))),
        ];
    }
}
