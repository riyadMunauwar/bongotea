<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\OrderComplete\OrderCompleteConfig;
use App\Support\OrderComplete\OrderCompleteCopy;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * What the Order Complete Page says, in the merchant's own words.
 *
 * Three boxes, all optional: an empty one is the merchant saying nothing, and
 * the page keeps the words it has always had. Each shows those words as its
 * placeholder, so what is in force reads without saving to find out. The
 * status line box belongs here too, and arrives with the layouts that draw it.
 */
class Words
{
    public static function section(): Section
    {
        return Section::make('Words')
            ->description('What the page says. Anything left empty keeps the page\'s own words. Write '.OrderCompleteCopy::ORDER_NUMBER_PLACEHOLDER.' or '.OrderCompleteCopy::TOTAL_PLACEHOLDER.' anywhere to put the order\'s own facts in a sentence.')
            ->columns(1)
            ->schema([
                TextInput::make('order_complete_config.headline')
                    ->label('Headline')
                    ->placeholder(OrderCompleteConfig::DEFAULT_HEADLINE)
                    ->rules([RefusedBy::rule(OrderCompleteConfig::headlineRefusal(...))]),

                Textarea::make('order_complete_config.message')
                    ->label('Message')
                    ->placeholder(OrderCompleteConfig::DEFAULT_MESSAGE)
                    ->helperText('The line or two beneath the headline.')
                    ->rows(2)
                    ->rules([RefusedBy::rule(OrderCompleteConfig::messageRefusal(...))]),

                Textarea::make('order_complete_config.note')
                    ->label('Note')
                    ->placeholder(OrderCompleteConfig::DEFAULT_NOTE)
                    ->helperText('What happens next: when you will call, when the parcel arrives.')
                    ->rows(2)
                    ->rules([RefusedBy::rule(OrderCompleteConfig::noteRefusal(...))]),
            ]);
    }
}
