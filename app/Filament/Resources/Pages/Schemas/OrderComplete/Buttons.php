<?php

namespace App\Filament\Resources\Pages\Schemas\OrderComplete;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\OrderComplete\OrderCompleteButton;
use App\Support\OrderComplete\OrderCompleteConfig;
use App\Support\OrderComplete\OrderCompleteCopy;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Where the Customer goes next.
 *
 * A primary button that works with nothing typed — "Continue shopping", back
 * to the Page — and an optional second one for a tracking page or a support
 * page the merchant hosts elsewhere. An empty second label is the merchant
 * saying there is no second button, so no layout draws an empty control.
 */
class Buttons
{
    public static function section(): Section
    {
        return Section::make('Buttons')
            ->description('Where the customer goes next. '.OrderCompleteCopy::ORDER_NUMBER_PLACEHOLDER.' and '.OrderCompleteCopy::TOTAL_PLACEHOLDER.' work in a label or a link. Leave the second button\'s label empty for no second button.')
            ->columns(2)
            ->schema([
                TextInput::make('order_complete_config.primary_button.label')
                    ->label('Primary button')
                    ->placeholder(OrderCompleteConfig::DEFAULT_PRIMARY_BUTTON_LABEL)
                    ->rules([RefusedBy::rule(OrderCompleteButton::labelRefusal(...))]),

                TextInput::make('order_complete_config.primary_button.link')
                    ->label('Primary button link')
                    ->placeholder('This page\'s own address')
                    ->helperText('A full web address, or a path on this site starting with /.')
                    ->rules([RefusedBy::rule(OrderCompleteButton::linkRefusal(...))]),

                TextInput::make('order_complete_config.secondary_button.label')
                    ->label('Second button')
                    ->helperText('Empty means no second button. '.OrderCompleteCopy::ORDER_NUMBER_PLACEHOLDER.' and '.OrderCompleteCopy::TOTAL_PLACEHOLDER.' work here too.')
                    ->rules([RefusedBy::rule(OrderCompleteButton::labelRefusal(...))]),

                TextInput::make('order_complete_config.secondary_button.link')
                    ->label('Second button link')
                    ->placeholder('This page\'s own address')
                    ->helperText('A full web address, or a path on this site starting with /.')
                    ->rules([RefusedBy::rule(OrderCompleteButton::linkRefusal(...))]),
            ]);
    }
}
