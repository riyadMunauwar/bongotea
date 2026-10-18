import { Link, router, usePage } from '@inertiajs/react';
import { feeText, packName, retailDeliveryNote } from '@/components/cart/figures';
import { PackStepper } from '@/components/cart/pack-chooser';
import { AddMoreLink, DetailsLink, ElsewhereHeading } from '@/components/cart/row-parts';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { checkoutFor } from '@/lib/contents';
import type { CartPack } from '@/lib/retail-cart';
import { useRetailCart, type UseRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { BlockCart } from '@/types';
import type { CartBlockProps } from '@/types/blocks';
import type { ProductCard } from '@/types/catalogue';
import { Picture, SectionHeading } from './shared';

/**
 * A retail Cart's page (ADR-0034, ADR-0038): its assigned Products by
 * Category, each with a stepper for every খুচরা Pack at its Pack Price and
 * MRP; teas the Contents hold that the Cart does not offer drawn first,
 * apart, gone once taken out; what can no longer be ordered flagged and left
 * out of every figure; a tea whose Packs together fall outside its খুচরা
 * Order Limit, or hold more than is free to sell, flagged at its row, holding
 * up the Checkout; and beside it the subtotal, the retail Delivery Charge,
 * the total and how far free delivery is — of this Cart's Contents alone.
 * "আরও চা যোগ করুন" goes back to the page the last Pack was bought from.
 */
export function RetailCart({ cart: drawn, heading, text, categories, others }: CartBlockProps & { cart: BlockCart }) {
    const retail = useRetailCart(drawn.id);
    const elsewhere = others.filter((product) => retail.packsOf(product.id).some((pack) => retail.countOf(pack.id) > 0));
    const isBare = categories.length === 0 && retail.isEmpty && retail.unorderable.length === 0;

    return (
        <section className="pt-6 pb-10 md:pt-10 md:pb-14">
            <div className="shell-container flex flex-col gap-4 md:gap-6">
                <SectionHeading heading={heading} text={text} />

                {isBare ? (
                    <EmptyBasket />
                ) : (
                    <div className="grid items-start gap-6 md:grid-cols-[minmax(0,1fr)_360px] md:gap-8">
                        <div className="flex flex-col gap-2">
                            {(elsewhere.length > 0 || retail.unorderable.length > 0) && (
                                <div className="flex flex-col gap-2">
                                    <ElsewhereHeading />
                                    {elsewhere.map((product) => (
                                        <ProductRow key={product.id} product={product} retail={retail} />
                                    ))}
                                    {retail.unorderable.map((line) => (
                                        <UnorderableLine
                                            key={line.packId}
                                            name={line.pack === null ? null : `${line.pack.productName} · ${packName(line.pack.grams)}`}
                                            onRemove={() => retail.remove(line.packId)}
                                        />
                                    ))}
                                </div>
                            )}
                            {categories.map((category) => (
                                <div key={category.id} style={themeStyle(category.theme)} className="flex flex-col gap-2">
                                    <div className="flex items-baseline gap-2 px-[2px] pt-[6px]">
                                        <span className="rounded-full bg-(--cat-tint) px-[10px] py-[3px] text-[13px] font-bold text-(--cat-text)">{category.name}</span>
                                        {category.note && <span className="text-[13px] text-muted">{category.note}</span>}
                                    </div>
                                    {category.products.map((product) => (
                                        <ProductRow key={product.id} product={product} retail={retail} />
                                    ))}
                                </div>
                            ))}
                            <AddMoreLink />
                        </div>
                        <Summary retail={retail} />
                    </div>
                )}
            </div>
        </section>
    );
}

function EmptyBasket() {
    return (
        <div className="flex flex-col items-center gap-3 rounded-[14px] border border-border bg-surface px-5 py-10 text-center">
            <b className="text-[18px] font-semibold text-ink">{bn.packs.empty}</b>
            <span className="max-w-[420px] text-[15px] leading-[1.6] text-muted">{bn.packs.emptyHint}</span>
            <Link href="/" className="rounded-[10px] bg-primary px-6 py-3 text-[15px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white">
                {bn.packs.shop}
            </Link>
        </div>
    );
}

/** A tea with a stepper for each of its খুচরা Packs; greyed while it is not Available, and drawn not at all without a Pack. */
function ProductRow({ product, retail }: { product: ProductCard; retail: UseRetailCart }) {
    const taka = useTaka();
    const packs = retail.packsOf(product.id);
    const limit = retail.limitOf(product.id);
    const isHeld = packs.some((pack) => retail.countOf(pack.id) > 0);
    const lineTotal = packs.reduce((sum, pack) => sum + pack.price * retail.countOf(pack.id), 0);

    if (packs.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-col gap-[10px] rounded-[12px] border p-3 md:flex-row md:items-start md:gap-4',
                isHeld ? 'border-green-200 bg-green-25' : 'border-border bg-white',
                !product.isAvailable && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <Link href={product.href} tabIndex={-1} aria-hidden className="flex-none">
                    <Picture src={product.imageUrl} alt="" stripes="stripes-light" className={cn('size-14 flex-none rounded-[8px]', !product.isAvailable && 'grayscale')} />
                </Link>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <Link href={product.href} className="text-[15px] font-semibold text-ink no-underline hover:text-primary">
                        {product.name}
                    </Link>
                    {limit !== null && (
                        <span role="alert" className="text-[13px] font-semibold text-eyebrow">
                            {limit === 'below' ? bn.packs.belowMinimum(packName(packs[0].minGrams ?? 0)) : bn.packs.aboveMaximum(packName(packs[0].maxGrams ?? 0))}
                        </span>
                    )}
                    {retail.isShort(product.id) && (
                        <span role="alert" className="text-[13px] font-semibold text-eyebrow">
                            {bn.packs.short}
                        </span>
                    )}
                    <DetailsLink href={product.href} className="self-start" />
                </div>
                {lineTotal > 0 && <b className="text-[15px] font-bold whitespace-nowrap text-ink md:hidden">{taka(lineTotal)}</b>}
            </div>
            <div className="flex flex-col gap-2 md:w-[320px] md:flex-none">
                {packs.map((pack) => (
                    <PackRow key={pack.id} pack={pack} retail={retail} />
                ))}
                {lineTotal > 0 && <b className="self-end text-[15px] font-bold text-ink max-md:hidden">{taka(lineTotal)}</b>}
            </div>
        </div>
    );
}

/** One খুচরা Pack on its tea's row: its weight, Pack Price with the MRP struck through, and a stepper — or why it cannot be had. */
function PackRow({ pack, retail }: { pack: CartPack; retail: UseRetailCart }) {
    const taka = useTaka();
    const count = retail.countOf(pack.id);
    /** স্টক শেষ greys a Pack with none held; one holding some keeps its stepper, so the buyer can bring it down. */
    const isGreyed = !pack.isOrderable || (count === 0 && retail.isSoldOut(pack.id));

    return (
        <div className="flex items-center gap-2">
            <span className="flex min-w-0 flex-1 flex-col text-[13px] leading-[1.4]">
                <b className="font-semibold text-ink">{packName(pack.grams)}</b>
                <span className="text-muted">
                    {bn.cartPage.packAt(taka(pack.price))}
                    {pack.price < pack.mrp && (
                        <>
                            {' '}
                            <s>{taka(pack.mrp)}</s>
                        </>
                    )}
                </span>
            </span>
            {isGreyed ? (
                <span className="text-[13px] font-semibold text-muted">{pack.isOrderable ? bn.packs.soldOut : bn.packs.unavailable}</span>
            ) : (
                <PackStepper count={count} max={retail.mostOf(pack.id)} onChange={(next) => retail.set(pack.id, next)} className="flex-none" />
            )}
        </div>
    );
}

/** A Pack held that cannot be ordered now: named where the store still knows it, and only removable. */
function UnorderableLine({ name, onRemove }: { name: string | null; onRemove: () => void }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-[12px] border border-dashed border-border bg-surface p-3 opacity-80">
            <span className="flex min-w-0 flex-col gap-1">
                <span className="truncate text-[15px] font-semibold text-muted">{name ?? bn.packs.gone}</span>
                {name !== null && <span className="text-[13px] text-muted">{bn.packs.unorderable}</span>}
            </span>
            <button type="button" onClick={onRemove} className="cursor-pointer border-0 bg-transparent p-0 text-[13px] font-semibold text-muted underline">
                {bn.packs.remove}
            </button>
        </div>
    );
}

function Summary({ retail }: { retail: UseRetailCart }) {
    const taka = useTaka();
    const { links } = usePage().props;
    const { priced, isEmpty, hasOutsideLimit, hasShort } = retail;
    const isHeldUp = isEmpty || hasOutsideLimit || hasShort;
    const note = retailDeliveryNote(priced, taka);

    return (
        <aside className="flex flex-col gap-4 rounded-[14px] border border-border bg-white p-5 md:sticky md:top-[150px] md:p-6">
            <span className="text-[20px] font-semibold text-ink">{bn.packs.summary}</span>
            <div className="flex flex-col gap-[10px]">
                <SummaryLine label={bn.packs.packCount} value={bn.packs.countOf(toBanglaDigits(priced.packCount))} />
                <SummaryLine label={bn.packs.subtotal} value={taka(priced.subtotal)} />
                <SummaryLine label={bn.packs.delivery} value={isEmpty ? bn.cart.noFigure : feeText(priced.deliveryCharge, taka)} accent />
                {note && <span className="text-right text-[12px] text-eyebrow">{note}</span>}
                <div className="flex items-baseline justify-between border-t border-divider pt-[14px]">
                    <span className="text-[16px] font-medium">{bn.packs.total}</span>
                    <b className="text-[28px] font-bold text-primary-dark">{taka(priced.total)}</b>
                </div>
                {priced.savings > 0 && <div className="text-right text-[14px] text-primary">{bn.packs.totalSaving(taka(priced.savings))}</div>}
            </div>
            <button
                type="button"
                disabled={isHeldUp}
                onClick={() => retail.cartId !== null && router.visit(checkoutFor(links.checkout, retail.cartId))}
                className={cn(
                    'w-full cursor-pointer rounded-[10px] border-0 p-[15px] text-center text-[16px] font-semibold text-white disabled:cursor-not-allowed',
                    isHeldUp ? 'bg-disabled' : 'bg-primary hover:bg-primary-dark',
                )}
            >
                {bn.packs.checkout}
            </button>
        </aside>
    );
}

function SummaryLine({ label, value, accent = false }: { label: string; value: string; accent?: boolean }) {
    return (
        <div className="flex justify-between text-[14px]">
            <span className="text-muted">{label}</span>
            <b className={cn('font-semibold', accent && 'text-primary')}>{value}</b>
        </div>
    );
}
