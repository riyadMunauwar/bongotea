import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BulkPackLine } from '@/components/cart/bulk-pack-line';
import { discountLabel, packName } from '@/components/cart/figures';
import { PackStepper } from '@/components/cart/pack-chooser';
import { Stepper } from '@/components/cart/stepper';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import { firstKilograms, rowAnchor, type CartProduct } from '@/lib/cart';
import { gramsOfPackets } from '@/lib/grams';
import type { CartPack } from '@/lib/retail-cart';
import { priceRetailCart } from '@/lib/retail-pricing';
import { withReturnPath } from '@/lib/return-path';
import { useCart } from '@/lib/use-cart';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { BulkProductCard, ProductDetail } from '@/types/catalogue';
import { RetailFrom } from './product-card';
import { firstTierPrice, lowestPrice } from './tier-ladder';

type Way = 'bulk' | 'retail';

/**
 * The buying side of a Product's page: its Category, Brand, name and short
 * description, then the controls for each way it is sold — a পাইকারি | খুচরা
 * toggle, starting on পাইকারি, only where it is sold both ways. A Product
 * greyed as unavailable or স্টক শেষ every way says so, with the hotline, in
 * place of the controls.
 */
export function PurchasePanel({ product, isGreyed, className }: { product: ProductDetail; isGreyed: boolean; className?: string }) {
    const retail = useRetailCart(usePage().props.headerCarts.retail?.id ?? null);
    const isSoldRetail = retail.packsOf(product.id).length > 0;
    const [way, setWay] = useState<Way>(product.isSoldBulk ? 'bulk' : 'retail');
    const shown: Way = product.isSoldBulk && (way === 'bulk' || !isSoldRetail) ? 'bulk' : 'retail';

    return (
        <div className={cn('flex flex-col gap-[18px]', className)}>
            <span className="flex flex-wrap items-center gap-2">
                <Link href={product.category.href} className="rounded-full bg-(--cat-tint) px-[11px] py-[3px] text-[13px] font-semibold text-(--cat-text) no-underline">
                    {product.category.name}
                </Link>
                <span className="text-[13px] text-muted">
                    {bn.catalogue.brand}: <b className="font-semibold text-ink">{product.brand.name}</b>
                </span>
                {product.badge && <span className="rounded-full bg-eyebrow px-[10px] py-[3px] text-[12px] font-semibold text-white">{product.badge}</span>}
            </span>

            <h1 className="m-0 text-[clamp(28px,3.6vw,44px)] leading-[1.25] font-semibold text-ink">{product.name}</h1>

            {product.shortDescription && <p className="m-0 text-[clamp(16px,1.4vw,19px)] leading-[1.7] text-muted">{product.shortDescription}</p>}

            {isGreyed ? (
                <>
                    <span className="self-start rounded-full bg-surface px-3 py-[6px] text-[14px] font-semibold text-muted">
                        {product.isAvailable ? bn.catalogue.soldOut : bn.catalogue.unavailable}
                    </span>
                    <HotlineNote note={product.isAvailable ? bn.product.soldOutNote : bn.product.unavailableNote} />
                </>
            ) : (
                <div className="flex flex-col gap-4 rounded-[16px] border border-border bg-white p-4 md:p-5">
                    {product.isSoldBulk && isSoldRetail && <WayToggle way={shown} onChange={setWay} />}
                    {shown === 'bulk' && product.isSoldBulk ? <BulkPurchase product={product} /> : <RetailPurchase product={product} />}
                </div>
            )}
        </div>
    );
}

/** পাইকারি | খুচরা, for a Product sold both ways. */
function WayToggle({ way, onChange }: { way: Way; onChange: (way: Way) => void }) {
    const ways: [Way, string][] = [
        ['bulk', bn.product.bulk],
        ['retail', bn.product.retail],
    ];

    return (
        <div role="radiogroup" aria-label={bn.product.waysOfSelling} className="grid grid-cols-2 gap-1 rounded-[10px] bg-surface p-1">
            {ways.map(([value, label]) => (
                <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={way === value}
                    onClick={() => onChange(value)}
                    className={cn(
                        'cursor-pointer rounded-[8px] border-0 px-3 py-[9px] text-[15px] font-semibold',
                        way === value ? 'bg-white text-primary-dark shadow-card' : 'bg-transparent text-muted hover:text-ink',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}

/** Buying by the Kilogram, or why it cannot be done now. */
function BulkPurchase({ product }: { product: BulkProductCard }) {
    const cart = useCart(usePage().props.headerCarts.bulk?.id ?? null);
    const chosen = cart.products.find((candidate) => candidate.id === product.id);

    if (chosen === undefined || cart.isSoldOut(product.id)) {
        return <WayNotice notice={bn.catalogue.bulkSoldOut} />;
    }

    return <BulkControls product={chosen} />;
}

/**
 * The Kilograms in the tea's default পাইকারি Pack, the packets they make,
 * the Tier Price per Kilogram for the Cart with this line in it, and the
 * line's total, all as they change. The choice is the buyer's until it is
 * added — as the pack chooser's count is — which sets the default Pack's
 * line in the Cart and goes on to its Bulk Order row, where its other Packs
 * are offered too.
 */
function BulkControls({ product }: { product: CartProduct }) {
    const { headerCarts } = usePage().props;
    const cart = useCart(usePage().props.headerCarts.bulk?.id ?? null);
    const taka = useTaka();
    const held = cart.lineOf(product.id);
    const [kilograms, setKilograms] = useState(() => firstKilograms(product, null));
    const most = cart.mostKilogramsOf(product.id);
    const least = Math.max(1, product.minKilograms ?? 1);
    const quote = cart.quote(product.id, kilograms, null);
    const canAdd = quote !== null && quote.line.packets !== null && quote.line.limit === null && !quote.line.isShort;

    /** The stored Cart appears as the page comes alive; start from what it holds of this tea in its default Pack. */
    useEffect(() => {
        if (held !== null) {
            setKilograms(held.kilograms);
        }
    }, [held?.kilograms]);

    return (
        <>
            <BulkPrices product={product} />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-[14px] font-semibold text-ink">{bn.product.kilogramsHeading}</span>
                <Stepper
                    kilograms={kilograms}
                    onChange={(next) => setKilograms(Math.min(most, Math.max(least, next)))}
                    editable
                    max={most}
                    className="h-11 rounded-[10px] border border-primary bg-green-50 text-primary-dark"
                    buttonClassName="h-full w-11 text-primary-dark"
                />
            </div>

            {quote !== null && (
                <>
                    <BulkPackLine line={quote.line} />

                    <dl className="m-0 flex flex-col gap-2 border-t border-divider pt-3 text-[14px]">
                        <div className="flex items-baseline justify-between gap-3">
                            <dt className="text-muted">{bn.product.tierNow(toBanglaDigits(quote.totalKilograms))}</dt>
                            <dd className="m-0 font-bold text-primary-dark">
                                {taka(quote.priced.tierPrice)}
                                <span className="text-[13px] font-normal text-muted">{bn.blocks.perKilogram}</span>
                            </dd>
                        </div>
                        <div className="flex items-baseline justify-between gap-3">
                            <dt className="text-muted">{bn.product.lineTotal}</dt>
                            <dd className="m-0 text-[20px] font-bold text-ink">{taka(quote.priced.lineTotal)}</dd>
                        </div>
                    </dl>
                </>
            )}

            <button
                type="button"
                disabled={!canAdd}
                onClick={() => {
                    cart.set(product.id, kilograms);
                    router.visit(`${headerCarts.bulk?.href ?? '/'}#${rowAnchor(product.id)}`);
                }}
                className="cursor-pointer rounded-[10px] border-0 bg-primary px-6 py-[14px] text-[16px] font-semibold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:bg-disabled"
            >
                {held === null ? bn.product.addBulk : bn.product.updateBulk}
            </button>
        </>
    );
}

/**
 * Buying in Packs: each খুচরা Pack as a chip with its Pack Price and MRP, a
 * count, and the weight and total they make, as they change. A Pack that
 * cannot be ordered now, or is স্টক শেষ, is greyed and cannot be picked.
 * Adding puts the Packs in the খুচরা কার্ট and goes on to it.
 */
function RetailPurchase({ product }: { product: ProductDetail }) {
    const { props, url } = usePage();
    const { headerCarts, retailPricing } = props;
    const retail = useRetailCart(usePage().props.headerCarts.retail?.id ?? null);
    const taka = useTaka();
    const packs = retail.packsOf(product.id);
    const isPickable = (pack: CartPack) => pack.isOrderable && !retail.isSoldOut(pack.id);
    const [chosenId, setChosenId] = useState<number | null>(() => packs.find(isPickable)?.id ?? null);
    const [count, setCount] = useState(1);
    const chosen = packs.find((pack) => pack.id === chosenId && isPickable(pack)) ?? null;

    if (chosen === null && !packs.some(isPickable)) {
        return packs.some((pack) => pack.isOrderable) ? <WayNotice notice={bn.packs.soldOut} /> : <WayNotice notice={bn.catalogue.unavailable} note={bn.product.unavailableNote} />;
    }

    /** What may still be added of the chosen Pack before its tea passes its খুচরা maximum or what is free, with what the basket already holds of it. */
    const room = chosen === null ? 0 : retail.mostOf(chosen.id) - retail.countOf(chosen.id);
    const most = Math.max(1, room);
    const shownCount = Math.min(count, most);
    const total = chosen === null ? 0 : priceRetailCart(retailPricing, [{ pack: chosen, count: shownCount }]).subtotal;

    return (
        <>
            <RetailFrom productId={product.id} />

            <div role="radiogroup" aria-label={bn.product.packHeading} className="flex flex-wrap gap-2">
                {packs.map((pack) => (
                    <button
                        key={pack.id}
                        type="button"
                        role="radio"
                        aria-checked={chosen?.id === pack.id}
                        disabled={!isPickable(pack)}
                        onClick={() => setChosenId(pack.id)}
                        className={cn(
                            'flex min-w-[104px] cursor-pointer flex-col items-start gap-[2px] rounded-[10px] border bg-white px-3 py-[10px] text-left disabled:cursor-not-allowed disabled:opacity-50',
                            chosen?.id === pack.id ? 'border-primary bg-green-50' : 'border-border',
                        )}
                    >
                        <b className="text-[15px] font-bold text-ink">{packName(pack.grams)}</b>
                        {isPickable(pack) ? (
                            <span className="flex flex-wrap items-baseline gap-x-[6px]">
                                <b className="text-[15px] font-bold text-primary-dark">{taka(pack.price)}</b>
                                {pack.price < pack.mrp && <s className="text-[12px] text-muted">{taka(pack.mrp)}</s>}
                            </span>
                        ) : (
                            <span className="text-[13px] font-semibold text-muted">{pack.isOrderable ? bn.packs.soldOut : bn.packs.unavailable}</span>
                        )}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-[14px] font-semibold text-ink">{bn.packs.count}</span>
                <PackStepper count={shownCount} max={most} onChange={(next) => setCount(Math.min(most, Math.max(1, next)))} />
            </div>

            {chosen !== null && (
                <dl className="m-0 flex flex-col gap-2 border-t border-divider pt-3 text-[14px]">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted">{bn.product.weight}</dt>
                        <dd className="m-0 font-semibold text-ink">{packName(gramsOfPackets(shownCount, chosen.grams))}</dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted">{bn.packs.total}</dt>
                        <dd className="m-0 text-[20px] font-bold text-ink">{taka(total)}</dd>
                    </div>
                    {chosen.price < chosen.mrp && <span className="text-right text-[13px] font-semibold text-primary">{bn.packs.saving(taka((chosen.mrp - chosen.price) * shownCount))}</span>}
                </dl>
            )}

            <button
                type="button"
                disabled={chosen === null || room < 1}
                onClick={() => {
                    if (chosen === null || room < 1) {
                        return;
                    }

                    retail.set(chosen.id, retail.countOf(chosen.id) + shownCount);
                    router.visit(withReturnPath(headerCarts.retail?.href ?? '/', url));
                }}
                className="cursor-pointer rounded-[10px] border-0 bg-primary px-6 py-[14px] text-[16px] font-semibold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:bg-disabled"
            >
                {bn.product.addRetail}
            </button>
        </>
    );
}

/** The price range a Kilogram of a পাইকারি Product runs over its Tiers, beside its MRP struck through. */
function BulkPrices({ product }: { product: CartProduct }) {
    const taka = useTaka();
    const first = firstTierPrice(product.tiers);
    const lowest = lowestPrice(product.tiers);

    return (
        <span className="text-[15px] text-muted">
            <b className="text-[24px] font-bold text-primary-dark">{lowest < first ? `${taka(lowest)}–${taka(first)}` : taka(first)}</b>
            {bn.blocks.perKilogram}
            {first < product.mrp && (
                <>
                    {' '}
                    {bn.catalogue.mrp} <s>{taka(product.mrp)}</s> <span className="font-semibold text-primary">{discountLabel(product.mrp, first, taka)}</span>
                </>
            )}
        </span>
    );
}

/** In place of one way's controls: why it cannot be bought that way now, with the hotline. */
function WayNotice({ notice, note = bn.product.soldOutNote }: { notice: string; note?: string }) {
    return (
        <div className="flex flex-col gap-2">
            <span className="self-start rounded-full bg-surface px-3 py-[6px] text-[14px] font-semibold text-muted">{notice}</span>
            <HotlineNote note={note} />
        </div>
    );
}

/** Why the tea cannot be had now, and the hotline to ask about it; nothing where the store has no hotline. */
function HotlineNote({ note }: { note: string }) {
    const { store } = usePage().props;

    if (!store.hotline) {
        return null;
    }

    return (
        <span className="text-[14px] leading-[1.6] text-muted">
            {note}{' '}
            <a href={store.hotline.href} className="font-semibold">
                {formatPhone(store.hotline.number)}
            </a>
        </span>
    );
}
