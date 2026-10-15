import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Picture } from '@/components/blocks/shared';
import { discountPercent } from '@/components/cart/figures';
import { PackChooserButton } from '@/components/cart/pack-chooser';
import { useCartChoice } from '@/components/cart/cart-chooser';
import { CartIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { firstKilograms } from '@/lib/cart';
import { useAdding } from '@/lib/use-adding';
import { useCart } from '@/lib/use-cart';
import { cn } from '@/lib/utils';
import { useTaka } from '@/lib/use-taka';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useIsSoldOut } from '@/lib/use-is-sold-out';
import type { BulkProductCard, ProductCard as ProductCardProps } from '@/types/catalogue';
import { StrengthBar } from './strength-bar';
import { firstTierPrice, lowestPrice, TierLadder } from './tier-ladder';

/**
 * A Product as every list draws it, plain and white in the manner of a
 * pharmacy shelf: its picture with the share its first Tier takes off the MRP
 * and its badge in the corner, its Category and Brand, its name, strength, its
 * first Tier's price beside the MRP struck through, the most a Tier takes off
 * the MRP, and its own Tier ladder, with the kilogram button, all for a
 * Product sold পাইকারি; and — for one sold খুচরা — its cheapest Pack Price
 * and the packet button, the price leading where it is not sold পাইকারি
 * (ADR-0036). Drawn small, so a row holds several, yet with nothing left off.
 * An unavailable Product is drawn greyed and says so, because a regular buyer
 * looks for the tea they always buy; so is one স্টক শেষ every way it is sold,
 * and one স্টক শেষ only পাইকারি says so in place of its kilogram button.
 * Both buttons only add (ADR-0038): the card shows no Cart's quantity.
 *
 * Sized by whoever places it: a grid cell, or a slot in a sideways row.
 */
export function ProductCard({ product, className }: { product: ProductCardProps; className?: string }) {
    const cart = useCart(null);
    const isSoldOut = useIsSoldOut(product);
    const isGreyed = !product.isAvailable || isSoldOut;
    const percentOff = product.isSoldBulk ? discountPercent(product.mrp, firstTierPrice(product.tiers)) : null;

    return (
        <article
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-border bg-white transition-[border-color,box-shadow] hover:border-green-200 hover:shadow-card',
                className,
            )}
        >
            <Link href={product.href} className="block p-[6px] pb-0 no-underline" tabIndex={-1} aria-hidden="true">
                <Picture
                    src={product.imageUrl}
                    alt=""
                    stripes="stripes-light"
                    label={bn.catalogue.imageOf(product.name)}
                    className={cn('flex aspect-square items-end rounded-[8px] bg-surface p-2', isGreyed && 'grayscale')}
                >
                    <span className="absolute top-[6px] left-[6px] flex flex-col items-start gap-1">
                        {percentOff && !isGreyed && (
                            <span className="rounded-[4px] bg-cart-badge px-[6px] py-[2px] text-[10px] font-bold text-white md:text-[11px]">
                                {bn.catalogue.percentOff(percentOff)}
                            </span>
                        )}
                        {product.badge && (
                            <span className="rounded-[4px] bg-eyebrow px-[6px] py-[2px] text-[10px] font-semibold text-white md:text-[11px]">{product.badge}</span>
                        )}
                    </span>
                </Picture>
            </Link>

            <div className={cn('flex flex-1 flex-col gap-1 p-[10px] md:p-3', isGreyed && 'opacity-70')}>
                <span className="truncate text-[10px] text-muted md:text-[11px]">
                    <Link href={product.category.href} className="font-semibold text-primary-dark no-underline hover:underline">
                        {product.category.name}
                    </Link>
                    {' · '}
                    {product.brand.name}
                </span>

                <Link href={product.href} className="text-[13px] leading-[1.35] font-semibold text-ink no-underline hover:text-primary md:text-[14px]">
                    {product.name}
                </Link>

                {product.strength !== null && (
                    <span className="flex items-center gap-[6px] text-[10px] text-muted md:text-[11px]">
                        {bn.catalogue.strength}
                        <StrengthBar bars={product.strength.bars} />
                        <b className="font-semibold text-ink">{product.strength.grade}</b>
                    </span>
                )}

                {product.isSoldBulk ? (
                    <BulkPrices product={product} cartKilograms={0} />
                ) : (
                    <RetailFrom productId={product.id} className="text-[15px] md:text-[17px]" />
                )}

                <div className="mt-auto flex flex-col gap-[6px] pt-[6px]">
                    {isGreyed ? (
                        <CardNotice>{product.isAvailable ? bn.catalogue.soldOut : bn.catalogue.unavailable}</CardNotice>
                    ) : !product.isSoldBulk ? null : cart.isSoldOut(product.id) ? (
                        <CardNotice>{bn.catalogue.bulkSoldOut}</CardNotice>
                    ) : (
                        <BulkAddButton product={product} />
                    )}
                    {!isGreyed && <PackChooserButton productId={product.id} productName={product.name} cartIds={product.cartIds} />}
                </div>
            </div>
        </article>
    );
}

/** In place of a card's buttons: why it cannot be ordered now. */
/** How long the card says where an add went. */
const ADDED_MILLISECONDS = 2500;

/**
 * পাইকারি কিনুন on a card (ADR-0038): adds the least a buyer may order of the
 * tea, in its default পাইকারি Pack, to the Cart the add-rule names — on top of
 * what is there — and leaves the buyer where they are, saying which Cart it
 * reached. It shows no Cart's quantity.
 */
function BulkAddButton({ product }: { product: BulkProductCard }) {
    const { pricing } = usePage().props;
    const adding = useAdding();
    const { choose, chooser } = useCartChoice();
    const [addedTo, setAddedTo] = useState<string | null>(null);

    useEffect(() => {
        if (addedTo === null) {
            return;
        }

        const timer = window.setTimeout(() => setAddedTo(null), ADDED_MILLISECONDS);

        return () => window.clearTimeout(timer);
    }, [addedTo]);

    const add = () => {
        const prices = pricing.products.find((candidate) => candidate.id === product.id);

        if (prices === undefined) {
            return;
        }

        choose(adding.ruleFor(product.cartIds, 'bulk'), (cart) => {
            adding.addKilograms(cart.id, product.id, firstKilograms(prices, null));
            setAddedTo(cart.name);
        });
    };

    return (
        <>
            <button
                type="button"
                onClick={add}
                className="box-border flex w-full cursor-pointer items-center justify-center gap-[6px] rounded-[6px] border-0 bg-primary px-1 py-2 text-[12px] font-semibold text-white hover:bg-primary-dark md:text-[13px]"
            >
                <CartIcon size={15} className="max-md:hidden" />
                {bn.cart.add} <span className="font-normal opacity-80">· {bn.cart.addUnit}</span>
            </button>
            {addedTo !== null && (
                <span role="status" className="text-center text-[11px] font-semibold text-primary-dark md:text-[12px]">
                    {bn.cartChooser.added(addedTo)}
                </span>
            )}
            {chooser}
        </>
    );
}

function CardNotice({ children }: { children: string }) {
    return (
        <span className="box-border flex w-full items-center justify-center rounded-[6px] bg-surface px-1 py-2 text-[12px] font-semibold text-muted md:text-[13px]">
            {children}
        </span>
    );
}

/** A পাইকারি Product's first Tier Price beside the MRP struck through, the most a Tier takes off, its খুচরা price where it has one, and its ladder. */
function BulkPrices({ product, cartKilograms }: { product: BulkProductCard; cartKilograms: number }) {
    const taka = useTaka();
    const first = firstTierPrice(product.tiers);
    const lowest = lowestPrice(product.tiers);
    const maxSaving = lowest < first ? product.mrp - lowest : 0;

    return (
        <>
            <span className="mt-[2px] flex flex-wrap items-baseline gap-x-[6px] gap-y-[2px]">
                <b className="text-[15px] font-bold text-ink md:text-[17px]">
                    {taka(first)}
                    <span className="text-[11px] font-medium text-muted md:text-[12px]">{bn.blocks.perKilogram}</span>
                </b>
                {first < product.mrp && <s className="text-[11px] text-muted md:text-[12px]">{taka(product.mrp)}</s>}
            </span>

            {maxSaving > 0 && (
                <span className="self-start rounded-[4px] bg-offer-tint px-[6px] py-px text-[10px] font-semibold text-cart-badge md:text-[11px]">
                    <span className="md:hidden">{bn.catalogue.upToOffShort(taka(maxSaving))}</span>
                    <span className="max-md:hidden">{bn.catalogue.upToOff(taka(maxSaving))}</span>
                </span>
            )}

            <RetailFromLine productId={product.id} />

            {product.tiers.length > 1 && (
                <div className="mt-[2px]">
                    <TierLadder mrp={product.mrp} tiers={product.tiers} cartKilograms={cartKilograms} />
                </div>
            )}
        </>
    );
}

/** A Product sold only খুচরা has no Tiers, so it leads with its cheapest খুচরা Pack Price. */
export function RetailFrom({ productId, className }: { productId: number; className?: string }) {
    const label = useRetailFromLabel(productId);

    if (label === null) {
        return null;
    }

    return <b className={cn('mt-[2px] text-[17px] font-extrabold text-ink md:mt-1 md:text-[24px]', className)}>{label}</b>;
}

/** The cheapest খুচরা Pack Price as a quiet line under a পাইকারি Product's prices, where it is sold খুচরা too. */
function RetailFromLine({ productId }: { productId: number }) {
    const label = useRetailFromLabel(productId);

    if (label === null) {
        return null;
    }

    return <span className="text-[11px] font-semibold text-primary-dark md:text-[12px]">{label}</span>;
}

/** "খুচরা ৳X থেকে" from a Product's cheapest খুচরা Pack, or null where it is not sold খুচরা. */
function useRetailFromLabel(productId: number): string | null {
    const taka = useTaka();
    const prices = useRetailCart(usePage().props.headerCarts.retail?.id ?? null)
        .packsOf(productId)
        .map((pack) => pack.price);

    return prices.length === 0 ? null : bn.catalogue.retailFrom(taka(Math.min(...prices)));
}
