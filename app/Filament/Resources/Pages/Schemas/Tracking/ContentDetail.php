<?php

namespace App\Filament\Resources\Pages\Schemas\Tracking;

use App\Support\Checkout\BuiltInField;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * How much this Page says about what was bought, and the one change that
 * would say more about who bought it.
 *
 * The two halves of a reported event pull in opposite directions here. What
 * is bought — `custom_data` — is a per-Page decision, because a Page selling
 * something Meta's business tool terms restrict has a real reason to withhold
 * product names while still reporting the money. Who bought it — `user_data`
 * — is deliberately not a switch anywhere: Meta computes match quality per
 * dataset rather than per Page, so a Page sending fewer match keys would
 * quietly degrade every other Page sharing that Destination.
 *
 * So the one thing worth saying about match quality is what would *raise* it,
 * and it is the same thing on nearly every Page: Email is built in and
 * defaults to off, and switching it on is the highest-value change available
 * to a Merchant.
 */
class ContentDetail
{
    public static function section(): Section
    {
        return Section::make('What is sent')
            ->columns(1)
            ->schema([
                self::suppression(),

                self::matchQualityHint(),
            ]);
    }

    public static function suppression(): Toggle
    {
        return Toggle::make('tracking_config.suppress_content_detail')
            ->label('Do not say what was bought')
            ->default(false)
            /** So the preview below follows the switch as it is set, rather than at the next save. */
            ->live()
            ->helperText('On, an event still carries the value, the currency, the item count and the order number, and carries no product name, product id or basket. For a Page selling something Meta\'s business tool terms restrict: optimisation and reporting are kept, the catalogue match is what is given up.');
    }

    /**
     * Drawn only while this Page does not ask for an email address, because a
     * Page that already does needs no advice — and because a hint that is
     * always there is one nobody reads.
     *
     * `Get` resolves against the whole schema, so reading the Checkout tab's
     * own field list from here needs nothing special.
     */
    private static function matchQualityHint(): Text
    {
        return Text::make('Switch the Email field on, under Checkout → Questions, and every Order from this Page carries one more hashed match key to Facebook. It is the single largest improvement to match quality available on a Page: phone number is always sent, and email is the one strong key most Pages do not ask for.')
            ->icon(Heroicon::OutlinedLightBulb)
            ->visible(fn (Get $get): bool => ! self::asksForEmail($get));
    }

    /**
     * Whether the Checkout tab, as it currently stands, asks for an email
     * address.
     *
     * Read off the fields repeater's raw state rather than through a stored
     * Config, because what matters is the form in front of the Merchant: they
     * may have switched it on one tab over and not saved yet. A state that
     * cannot be read that way answers "yes", so an unreadable shape hides the
     * hint rather than showing one that may be wrong.
     */
    private static function asksForEmail(Get $get): bool
    {
        $fields = $get('checkout_config.fields');

        if (! is_array($fields)) {
            return true;
        }

        foreach ($fields as $field) {
            if (is_array($field) && ($field['key'] ?? null) === BuiltInField::Email->value) {
                return (bool) ($field['enabled'] ?? false);
            }
        }

        return true;
    }
}
