<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * What this Page sells, whether the customer chooses between them, and which
 * of them the choice opens on.
 *
 * The controls are contributed separately because a concern hands over one
 * component at a time and never a list — not because anything is interleaved
 * between them. The layout is met before any of them, on its own nested tab,
 * so a merchant reaches the picker having already decided the thing that gates
 * it; `CheckoutTab` is where that order lives.
 */
class Products
{
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
         * Held shut on a form-only page, which draws no
         * products and so has nothing to put a checkbox
         * beside. Held shut rather than hidden, and
         * dehydrated so the switch survives: a merchant
         * trying the other layout gets their picker back
         * when they switch away from it. The same
         * refusal is made again as the Config is read,
         * because a picker left standing there would
         * compile a rule demanding a choice the form
         * never offered — every order on a live page
         * refused for a box no customer can find.
         */
        return Toggle::make('checkout_config.product_picker')
            ->label('Let the customer choose')
            ->default(false)
            ->disabled(fn (Get $get): bool => ! LayoutChoice::selected($get)->showsProducts())
            ->dehydrated()
            /** Live so the default selection below opens or closes with the switch that decides whether it is drawn at all. */
            ->live()
            ->helperText(fn (Get $get): string => LayoutChoice::selected($get)->showsProducts()
                ? 'Each product gets a checkbox, and its quantity appears only once it is ticked. Choose below which of them open ticked. A page selling one product asks nothing and sells it.'
                : 'Not on a form-only page: there are no products on screen to tick. The order is for everything the page sells. Your setting is kept for when you switch back.');
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
     * Held shut where there is no picker to open, for the reason the picker
     * itself is held shut on a form-only layout: a tick nobody can see and
     * nobody can untick. Dehydrated, so the merchant's list survives a save
     * made while it could not be honoured.
     */
    public static function preTicked(): CheckboxList
    {
        return CheckboxList::make('checkout_config.default_selection')
            ->label('Ticked when the page opens')
            ->options(fn (Get $get): array => self::linkedProducts($get))
            /** Live, because the warning below is about how many are ticked. */
            ->live()
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
     * Whether a picker is being configured at all: the merchant's switch and
     * the layout that would have to draw it, which is the same pair
     * `CheckoutConfig::picksProducts()` reads a stored Config through.
     */
    private static function picksProducts(Get $get): bool
    {
        return (bool) $get('checkout_config.product_picker')
            && LayoutChoice::selected($get)->showsProducts();
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
