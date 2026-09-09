<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Whether the checkout shows the Product List, what this Page sells, whether
 * the customer chooses between them, and which of them the choice opens on.
 *
 * The controls are contributed separately because a concern hands over one
 * component at a time and never a list — not because anything is interleaved
 * between them. The switch is met before any of them, so a merchant reaches
 * the picker having already decided the thing that gates it; `CheckoutTab` is
 * where that order lives.
 */
class Products
{
    /**
     * Whether the checkout draws the Product List at all.
     *
     * Off for a page whose own markup has already sold the product above the
     * fold, on any layout: the checkout stops repeating it in smaller type and
     * still sells everything the page links, one of each. What this decides
     * about the two controls beneath it is whether they are drawn — there is
     * nothing on screen to pick between or to tick while the list is hidden —
     * and never what they hold.
     */
    public static function listShown(): Toggle
    {
        return Toggle::make('checkout_config.show_products')
            ->label('Show the products in the checkout')
            ->default(true)
            /**
             * Refused twice, like everything in a Config: worded here for
             * anything that is not a yes or a no, and dropped for the default
             * on read. A switch cannot type anything else, so this is the
             * refusal a Config written by another route meets.
             */
            ->rule('boolean')
            /** Live so the picker and the default selection below open and close with it, rather than after a save. */
            ->live()
            ->helperText(fn (Get $get): string => self::showsProducts($get)
                ? 'The checkout draws the product list: each product with its picture, its price and a quantity. Switch it off for a page whose own content already sells the product above the form.'
                : 'The checkout draws no product list. The order is still for everything the page sells, one of each, and the total still shows it. The customer cannot be asked to choose while the list is hidden; that switch and its ticks are kept for when you show it again.');
    }

    public static function sold(): Select
    {
        /**
         * Shows the live links only, and leaves the rest
         * alone: Filament detaches what its own relationship
         * query returned and is missing from the selection
         * (Select::saveRelationshipsAfterStateUpdated), and a
         * soft-deleted Product is in neither. So saving an
         * unrelated field does not quietly drop the link the
         * Pages list is flagging as lost.
         */
        return Select::make('products')
            ->relationship('products', 'name')
            ->multiple()
            ->preload()
            ->searchable()
            /** Live so unlinking a product takes its tick box out of the default selection below, rather than after a save. */
            ->live()
            ->helperText('[checkout] sells everything linked here, each with its own quantity, unless you let the customer choose below. Link at least one to publish.');
    }

    public static function picker(): Toggle
    {
        /**
         * Whether the customer chooses which of them to
         * buy. Off, so linking a second product sells
         * both together the way this page always has,
         * and offering a choice is something a merchant
         * asks for rather than something a link does to
         * them.
         *
         * No control decides how many products make a
         * choice worth asking about: a page down to one
         * sells it implicitly, which `Page` answers for
         * the form and the capture together.
         *
         * Not drawn while the product list is hidden,
         * which draws no products and so has nothing to
         * put a checkbox beside. Hidden rather than held
         * shut, because a control about something that is
         * not on the page is not a control the merchant
         * can act on; and dehydrated while hidden so the
         * switch survives, because a hidden field is
         * dropped from the saved state unless it says
         * otherwise — a merchant showing the list again
         * gets their picker back as they left it. The
         * same refusal is made again as the Config is
         * read, because a picker left standing there
         * would compile a rule demanding a choice the
         * form never offered — every order on a live page
         * refused for a box no customer can find.
         */
        return Toggle::make('checkout_config.product_picker')
            ->label('Let the customer choose')
            ->default(false)
            ->visible(fn (Get $get): bool => self::showsProducts($get))
            ->dehydratedWhenHidden()
            /** Live so the default selection below opens or closes with the switch that decides whether it is drawn at all. */
            ->live()
            ->helperText('Each product gets a checkbox, and its quantity appears only once it is ticked. Choose below which of them open ticked. A page selling one product asks nothing and sells it.');
    }

    /**
     * Which of them the picker opens ticked.
     *
     * Ticks are named, never counted: the value is a list of product ids, so
     * reordering the links above cannot retarget a page that is spending
     * money. A tick naming a product this page has stopped selling — unlinked
     * here, or soft-deleted out from under it — is kept in the Config and
     * dropped as the page is drawn, so a product coming back out of the bin
     * opens ticked again the way the merchant left it.
     *
     * Not drawn while the product list is hidden, for the reason the picker
     * itself is not: a tick nobody can see and nobody can untick. Held shut
     * where the list is shown but there is no picker to open. Dehydrated in
     * both cases, so the merchant's list survives a save made while it could
     * not be honoured.
     */
    public static function preTicked(): CheckboxList
    {
        return CheckboxList::make('checkout_config.default_selection')
            ->label('Ticked when the page opens')
            ->options(fn (Get $get): array => self::linkedProducts($get))
            /** Live, because the warning below is about how many are ticked. */
            ->live()
            ->visible(fn (Get $get): bool => self::showsProducts($get))
            ->dehydratedWhenHidden()
            ->disabled(fn (Get $get): bool => ! self::picksProducts($get))
            ->dehydrated()
            /**
             * No select-all. Ticking everything is the expensive case ADR-0010
             * wants chosen knowingly, and a button that reaches it in one click
             * lands past the warning that fires at two.
             */
            ->helperText(fn (Get $get): string => self::preTickedHelp($get));
    }

    /**
     * What the merchant is warned about, at the moment they make the choice.
     *
     * One ticked is a default the customer sees a price from and then changes,
     * which is the case this control exists for: a picker opening on nothing
     * shows a total of zero and reads as a broken form. Two or more is a
     * bundle the merchant is asserting, and on a cash-on-delivery page an
     * extra nobody chose is not a larger sale but a refused parcel and a
     * courier fee in both directions. Allowed, because a merchant selling a
     * set that is normally bought together knows their own products better
     * than this rule does — and said out loud here, so the risky case is
     * chosen knowingly rather than reached by accident (ADR-0010).
     */
    private static function preTickedHelp(Get $get): string
    {
        if (! self::picksProducts($get)) {
            return 'Nothing to tick while the customer is not choosing. Your selection is kept for when they are.';
        }

        $ticked = count((array) $get('checkout_config.default_selection'));

        if ($ticked > 1) {
            return 'Ticking more than one puts a bundle in front of the customer. On cash on delivery, an extra nobody chose is a refused parcel and courier fees both ways — tick several only where they are genuinely bought together.';
        }

        return 'Leave everything unticked and the customer starts at a total of nothing. Tick one and they meet a price they can then change. What they order is what is ticked when they send it.';
    }

    /**
     * Whether the product list is being drawn at all, as the tab stands.
     *
     * Read as a stored Config reads it — absent is shown — so a half-filled
     * create screen and a Config written by another route answer the same way.
     */
    private static function showsProducts(Get $get): bool
    {
        return (bool) ($get('checkout_config.show_products') ?? true);
    }

    /**
     * Whether a picker is being configured at all: the list that would have to
     * draw it and the merchant's switch, which is the same pair
     * `CheckoutConfig::picksProducts()` reads a stored Config through.
     */
    private static function picksProducts(Get $get): bool
    {
        return self::showsProducts($get)
            && (bool) $get('checkout_config.product_picker');
    }

    /**
     * The products the page is linked to right now, named and priced.
     *
     * Read from the links above as they stand in the form rather than from the
     * saved page, so a product linked and pre-ticked in one visit does not
     * need a save in between. Soft-deleted products are in neither, so nothing
     * here offers a tick against something the page has already stopped
     * selling.
     *
     * @return array<int, string>
     */
    private static function linkedProducts(Get $get): array
    {
        $linked = array_filter((array) $get('products'), 'is_numeric');

        if ($linked === []) {
            return [];
        }

        return Product::query()
            ->whereKey($linked)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => $product->name.' — '.Money::format($product->price),
            ])
            ->all();
    }
}
