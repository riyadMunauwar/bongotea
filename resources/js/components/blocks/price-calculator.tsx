import { router, usePage } from '@inertiajs/react';
import { currentStepRange, discountLabel, feeText } from '@/components/cart/figures';
import { ClearAll } from '@/components/cart/clear-all';
import { Stepper } from '@/components/cart/stepper';
import { tierRange } from '@/components/catalogue/tier-ladder';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { tierFor } from '@/lib/pricing';
import { useCart, type UseCart } from '@/lib/use-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { PriceCalculatorProps } from '@/types/blocks';
import type { BulkProductCard } from '@/types/catalogue';

/**
 * "হিসাব করে দেখুন": every published Product by Category with −/+, beside the
 * dark panel adding them up — the ladder of weights with the Cart's step,
 * each line, the saving, the Delivery Charge and the total. It is the Cart
 * itself, not a scratch copy, so its button only goes on to the Bulk Order
 * page; and emptying it asks first, since that empties the Cart.
 */
export function PriceCalculator({ heading, text, categories }: PriceCalculatorProps) {
    const cart = useCart(usePage().props.startingCarts.bulk);

    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="shell-container grid items-start gap-5 py-10 md:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] md:py-[72px]">
            <div className="overflow-hidden rounded-[20px] border border-border bg-white">
                <div className="flex items-center justify-between gap-3 border-b border-border bg-surface px-5 py-4">
                    <span className="flex flex-col gap-[2px]">
                        <b className="text-[17px] font-bold text-ink">{heading ?? bn.calculator.heading}</b>
                        <span className="text-[13px] text-muted">{text ?? bn.calculator.text}</span>
                    </span>
                    <ClearAll cart={cart} />
                </div>
                {categories.map((category) => (
                    <div key={category.id} style={themeStyle(category.theme)} className="flex flex-col">
                        <div className="flex items-center gap-2 px-5 pt-[10px] pb-[6px]">
                            <span className="rounded-full bg-(--cat-tint) px-[10px] py-[2px] text-[12px] font-bold text-(--cat-text)">{category.name}</span>
                        </div>
                        {category.products.map((product) => (
                            <Row key={product.id} product={product} cart={cart} />
                        ))}
                    </div>
                ))}
                <div className="h-1" />
            </div>

            <Panel cart={cart} products={categories.flatMap((category) => category.products)} />
        </section>
    );
}

function Row({ product, cart }: { product: BulkProductCard; cart: UseCart }) {
    const taka = useTaka();
    const kilograms = cart.kilogramsOf(product.id);
    const price = tierFor(product, cart.priced.totalKilograms).price;
    /** স্টক শেষ greys a row with nothing in the Cart; one holding Kilograms keeps its stepper, so the buyer can bring it down. */
    const isGreyed = !product.isAvailable || (kilograms === 0 && cart.isSoldOut(product.id));

    return (
        <div
            className={cn(
                'mx-2 mb-2 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 rounded-[12px] border p-3 transition-colors md:mx-3',
                kilograms > 0 ? 'border-green-200 bg-white' : 'border-divider bg-green-25',
                isGreyed && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 flex-col gap-[3px]">
                <b className="text-[15px] font-semibold text-ink">{product.name}</b>
                <span className="flex flex-wrap items-baseline gap-[6px] text-[13px]">
                    <b className="font-bold text-primary-dark">
                        {taka(price)}
                        {bn.blocks.perKilogram}
                    </b>
                    {price < product.mrp && (
                        <>
                            <span className="text-muted line-through opacity-75">{taka(product.mrp)}</span>
                            <span className="rounded-full bg-eyebrow px-[7px] py-px text-[11px] font-bold text-white">{discountLabel(product.mrp, price, taka)}</span>
                        </>
                    )}
                </span>
            </div>
            {!isGreyed ? (
                <Stepper
                    kilograms={kilograms}
                    onChange={(next) => cart.set(product.id, next)}
                    max={cart.mostKilogramsOf(product.id)}
                    className="rounded-[10px] border border-input bg-white text-[15px] text-ink"
                    buttonClassName="size-10 text-primary"
                />
            ) : (
                <span className="text-[13px] font-semibold text-muted">{product.isAvailable ? bn.catalogue.soldOut : bn.catalogue.unavailable}</span>
            )}
            {kilograms > 0 && (
                <div className="col-span-full flex items-baseline justify-between gap-2 border-t border-dashed border-border pt-2 text-[13px] text-muted">
                    <span>
                        {bn.cart.kilogramsOf(toBanglaDigits(kilograms))} × {taka(price)}
                        {price < product.mrp && <span className="font-semibold text-primary"> · {bn.calculator.lineSaving(taka((product.mrp - price) * kilograms))}</span>}
                    </span>
                    <b className="text-[16px] font-bold text-ink">{taka(price * kilograms)}</b>
                </div>
            )}
        </div>
    );
}

function Panel({ cart, products }: { cart: UseCart; products: BulkProductCard[] }) {
    const taka = useTaka();
    const { links } = usePage().props;
    const { priced, steps } = cart;
    const total = priced.totalKilograms;
    const currentIndex = steps.findIndex((step) => step.current);
    const current = currentStepRange(steps);
    const names = new Map(products.map((product) => [product.id, product.name]));
    const drop = priced.nextPriceDrop;

    return (
        <div className="flex flex-col gap-[18px] rounded-[20px] bg-night px-[18px] py-[22px] text-white md:sticky md:top-[180px] md:p-[30px]">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-[15px] font-semibold text-gold-accent">{bn.calculator.summary}</span>
                <span className="text-[13px] text-night-muted">{bn.calculator.totalKilograms(toBanglaDigits(total))}</span>
            </div>

            <div className="flex flex-col gap-2">
                <div className="grid gap-[3px]" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
                    {steps.map((step, index) => (
                        <span key={step.min} className={cn('block h-[6px] rounded-full transition-colors duration-300', index <= currentIndex ? 'bg-gold-accent' : 'bg-white/15')} />
                    ))}
                </div>
                <div className="grid gap-[3px]" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
                    {steps.map((step) => (
                        <span key={step.min} className={cn('text-center text-[10px] whitespace-nowrap', step.current ? 'font-bold text-white' : 'font-medium text-night-faint')}>
                            {tierRange(step)}
                        </span>
                    ))}
                </div>
                <span className="text-[13px] font-semibold text-gold-accent">{current === null ? bn.calculator.addTea : bn.calculator.stepOf(current)}</span>
            </div>

            {priced.lines.length > 0 && (
                <div className="flex flex-col gap-2 border-y border-white/12 py-[14px]">
                    {priced.lines.map((line, index) => (
                        <div key={index} className="flex justify-between gap-[10px] text-[14px]">
                            <span className="min-w-0 text-on-dark-sub">
                                {names.get(line.productId)}{' '}
                                <span className="text-night-faint">
                                    · {bn.cart.kilogramsOf(toBanglaDigits(line.kilograms))} × {taka(line.tierPrice)}
                                </span>
                            </span>
                            <b className="font-semibold whitespace-nowrap">{taka(line.lineTotal)}</b>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-[6px]">
                {priced.savings > 0 && (
                    <>
                        <div className="flex justify-between text-[14px] text-night-muted">
                            <span>{bn.calculator.atMrp}</span>
                            <span className="line-through">{taka(priced.subtotal + priced.savings)}</span>
                        </div>
                        <div className="flex justify-between text-[14px] font-semibold text-gold-accent">
                            <span>{bn.calculator.saving}</span>
                            <span>− {taka(priced.savings)}</span>
                        </div>
                    </>
                )}
                <div className="flex justify-between text-[14px] text-on-dark-sub">
                    <span>{bn.bulkOrder.teaPrice}</span>
                    <span>{taka(priced.subtotal)}</span>
                </div>
                <div className="flex justify-between text-[14px] text-on-dark-sub">
                    <span>{bn.bulkOrder.delivery}</span>
                    <span className="font-semibold text-gold-accent">{total === 0 ? bn.cart.noFigure : feeText(priced.deliveryCharge, taka)}</span>
                </div>
                <div className="flex items-baseline justify-between gap-3 border-t border-white/12 pt-[6px]">
                    <span className="text-[15px]">{bn.bulkOrder.total}</span>
                    <b className="text-[clamp(28px,3vw,36px)] font-extrabold">{taka(priced.total)}</b>
                </div>
            </div>

            <div className="rounded-[12px] bg-gold-accent/15 px-[14px] py-3 text-[14px] leading-[1.6] text-night-text">
                {total === 0 ? bn.calculator.emptyHint : drop === null ? bn.calculator.best : bn.calculator.next(toBanglaDigits(drop.kilogramsToGo), taka(drop.extraSaving))}
            </div>

            <button
                type="button"
                onClick={() => router.visit(links.bulkOrder)}
                className="cursor-pointer rounded-[10px] border-0 bg-gold-accent p-[14px] text-center text-[16px] font-bold text-night hover:bg-gold-hover"
            >
                {bn.calculator.order}
            </button>
        </div>
    );
}
