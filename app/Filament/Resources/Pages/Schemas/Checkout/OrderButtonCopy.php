<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\Checkout\OrderButton;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * What the order button says, and the line beneath it.
 *
 * Two boxes, both optional: an empty one is the merchant saying nothing, and
 * the button keeps the words it has always had. Neither is a Style Token — a
 * sentence is not a custom property — but it sits on the Appearance tab because
 * that is where a merchant goes to change how the checkout reads, and a sixth
 * tab for two boxes would be a concern with nothing else in it.
 */
class OrderButtonCopy
{
    public static function section(): Section
    {
        /**
         * Headed, unlike the token section above it, because the
         * tab is headed Appearance and this is not the whole of
         * that: a merchant scanning the tab needs the word that
         * says where the tokens stop and the copy starts.
         */
        return Section::make('Order button')
            ->description('What the button says and the line beneath it. Anything left empty keeps the checkout\'s own words.')
            ->columns(1)
            ->schema([
                TextInput::make('checkout_config.order_button.wording')
                    ->label('Button text')
                    /** What is in force while the box is empty, so it reads without saving to find out. */
                    ->placeholder(OrderButton::DEFAULT_WORDING)
                    ->helperText('Write '.OrderButton::TOTAL_PLACEHOLDER.' anywhere in it to put the live total on the button, e.g. "Order for '.OrderButton::TOTAL_PLACEHOLDER.'".')
                    ->rules([RefusedBy::rule(OrderButton::wordingRefusal(...))]),

                TextInput::make('checkout_config.order_button.note')
                    ->label('Line beneath the button')
                    ->placeholder(OrderButton::DEFAULT_NOTE)
                    ->helperText('A line of reassurance about how the customer pays.')
                    ->rules([RefusedBy::rule(OrderButton::noteRefusal(...))]),
            ]);
    }
}
