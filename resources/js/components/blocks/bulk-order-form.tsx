import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BulkPackLine } from '@/components/cart/bulk-pack-line';
import { deliveryNote, discountLabel, feeText, nextDropText, stepName } from '@/components/cart/figures';
import { ClearAll } from '@/components/cart/clear-all';
import { Stepper } from '@/components/cart/stepper';
import { tierRange } from '@/components/catalogue/tier-ladder';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { rowAnchor, rowFromAnchor, type PriceStep } from '@/lib/cart';
import { nextDropAbove, tierFor } from '@/lib/pricing';
import { useCart, type UseCart } from '@/lib/use-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { BulkOrderFormProps } from '@/types/blocks';
import type { BulkProductCard } from '@/types/catalogue';
import { Picture, SectionHeading } from './shared';

/** The quick-set chips' Kilograms. */
const QUICK_KILOGRAMS = [1, 5, 10, 20];

/** How long a row added from elsewhere stays highlighted, as the mockup has it. */
const FLASH_MILLISECONDS = 1600;

/**
 * The Cart as a form: every published Product by Category, each row with its
 * price now — the MRP struck through and what is off it whenever the price
 * is below it — what it will cost at its next cheaper Tier, −/+, a
 * typed quantity and quick-set chips, and its line total. Beside it (below
 * on a phone, as a bar at the bottom) the summary of the whole Cart, and
 * above the rows "সব মুছুন" while there is anything to clear. A row
 * named in the address (`#tea-12`, from a Product page's add button) is
 * scrolled to and flashed.
 */
export function BulkOrderForm({ heading, text, quickChips, showTierList, categories }: BulkOrderFormProps) {
    const cart = useCart();
    const flashed = useFlashedRow();
    const steps = cart.steps;

    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="pt-6 md:pt-10">
            <div className="shell-container flex flex-col gap-4 md:gap-6">
                <SectionHeading heading={heading} text={text} />

                {showTierList && (
                    <div className="snap-row !gap-[6px] md:hidden">
                        <StepCells steps={steps} className="flex-none px-[10px] py-[6px] text-[11px]" />
                    </div>
                )}

                <div className="grid items-start gap-8 pb-5 md:grid-cols-[minmax(0,1fr)_360px] md:pb-14">
                    <div className="flex flex-col gap-2 min-[1100px]:gap-[10px]">
                        {!cart.isEmpty && (
                            <div className="flex justify-end">
                                <ClearAll cart={cart} />
                            </div>
                        )}
                        {categories.map((category) => (
                            <div key={category.id} style={themeStyle(category.theme)} className="flex flex-col gap-2 min-[1100px]:gap-[10px]">
                                <div className="flex items-baseline gap-2 px-[2px] pt-[6px]">
                                    <span className="rounded-full bg-(--cat-tint) px-[10px] py-[3px] text-[13px] font-bold text-(--cat-text)">{category.name}</span>
                                    {category.note && <span className="text-[13px] text-muted">{category.note}</span>}
                                </div>
                                {category.products.map((product) => (
                                    <Row key={product.id} product={product} cart={cart} quickChips={quickChips} flashed={flashed === product.id} />
                                ))}
                            </div>
                        ))}
                        {quickChips && <span className="mt-1 text-center text-[12px] text-muted md:hidden">{bn.bulkOrder.chipsHint}</span>}
                    </div>

                    <Summary cart={cart} steps={showTierList ? steps : null} />
                </div>
            </div>

            <BottomBar cart={cart} />
        </section>
    );
}

/** The Product named in the address when the form appeared, for as long as it flashes. */
function useFlashedRow(): number | null {
    const [flashed, setFlashed] = useState<number | null>(null);

    useEffect(() => {
        const productId = rowFromAnchor(window.location.hash);

        if (productId === null) {
            return;
        }

        setFlashed(productId);
        document.getElementById(rowAnchor(productId))?.scrollIntoView({ block: 'center' });

        const timer = window.setTimeout(() => setFlashed(null), FLASH_MILLISECONDS);

        return () => window.clearTimeout(timer);
    }, []);

    return flashed;
}

function Row({ product, cart, quickChips, flashed }: { product: BulkProductCard; cart: UseCart; quickChips: boolean; flashed: boolean }) {
    const taka = useTaka();
    const kilograms = cart.kilogramsOf(product.id);
    const line = cart.lineOf(product.id);
    const total = cart.priced.totalKilograms;
    const tier = tierFor(product, total);
    const next = product.tiers.length > 1 ? nextDropAbove(product, total) : null;
    const set = (value: number) => cart.set(product.id, value);
    /** স্টক শেষ greys a row with nothing in the Cart; one holding Kilograms keeps its stepper, so the buyer can bring it down. */
    const isGreyed = !product.isAvailable || (kilograms === 0 && cart.isSoldOut(product.id));

    return (
        <div
            id={rowAnchor(product.id)}
            className={cn(
                'flex scroll-mt-[130px] flex-col gap-[10px] rounded-[12px] border p-3 transition-colors duration-300 min-[1100px]:flex-row min-[1100px]:items-center min-[1100px]:gap-4 min-[1100px]:px-4 min-[1100px]:py-[14px] lg:scroll-mt-[170px]',
                flashed ? 'border-primary bg-green-50' : kilograms > 0 ? 'border-green-200 bg-green-25' : 'border-border bg-white',
                isGreyed && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 flex-1 items-center gap-[10px] min-[1100px]:gap-4">
                <Picture
                    src={product.imageUrl}
                    alt=""
                    stripes="stripes-light"
                    className={cn('size-10 flex-none rounded-[8px] min-[1100px]:size-14', isGreyed && 'grayscale')}
                />
                <div className="flex min-w-0 flex-1 flex-col min-[1100px]:gap-[2px]">
                    <b className="text-[15px] leading-[1.4] font-semibold text-ink min-[1100px]:text-[17px]">{product.name}</b>
                    {product.shortDescription && (
                        <span className="hidden truncate text-[14px] text-muted min-[1100px]:block">{product.shortDescription}</span>
                    )}
                    <span className="flex flex-wrap items-baseline gap-[6px] text-[13px] leading-[1.5] min-[1100px]:text-[14px]">
                        <b className="font-bold text-primary-dark">
                            {taka(tier.price)}
                            {bn.blocks.perKilogram}
                        </b>
                        {tier.price < product.mrp && (
                            <>
                                <span className="text-muted line-through opacity-75">{taka(product.mrp)}</span>
                                <span className="rounded-full bg-eyebrow px-[7px] py-px text-[11px] font-bold whitespace-nowrap text-white">
                                    {discountLabel(product.mrp, tier.price, taka)}
                                </span>
                            </>
                        )}
                    </span>
                    {!isGreyed && next !== null && (
                        <span className="text-[12px] leading-[1.45] text-(--cat-text)">
                            {bn.bulkOrder.rowNext(toBanglaDigits(next.min - total), discountLabel(product.mrp, next.price, taka) ?? '', taka(next.price))}
                        </span>
                    )}
                    {!isGreyed && next === null && product.tiers.length > 1 && total > 0 && (
                        <span className="text-[12px] font-semibold text-primary">{bn.bulkOrder.bestNow}</span>
                    )}
                    {line !== null && <BulkPackLine line={line} cart={cart} className="mt-1" />}
                </div>
                {kilograms > 0 && (
                    <span className="text-[13px] font-bold whitespace-nowrap text-primary-dark min-[1100px]:text-[15px]">{taka(tier.price * kilograms)}</span>
                )}
            </div>

            {!isGreyed ? (
                <div className="flex items-center gap-2 min-[1100px]:gap-[6px]">
                    {quickChips && (
                        <div className="grid max-w-[260px] min-w-0 flex-1 grid-cols-4 gap-1 max-md:max-w-none min-[1100px]:flex min-[1100px]:max-w-none min-[1100px]:gap-[6px]">
                            {QUICK_KILOGRAMS.map((quick) => (
                                <button
                                    key={quick}
                                    type="button"
                                    aria-pressed={kilograms === quick}
                                    onClick={() => set(quick)}
                                    className={cn(
                                        'flex h-10 cursor-pointer items-center justify-center rounded-[8px] border text-[14px] font-semibold min-[1100px]:h-9 min-[1100px]:min-w-9 min-[1100px]:px-[10px]',
                                        kilograms === quick ? 'border-primary bg-primary text-white' : 'border-input bg-white text-primary',
                                    )}
                                >
                                    {toBanglaDigits(quick)}
                                </button>
                            ))}
                        </div>
                    )}
                    <Stepper
                        kilograms={kilograms}
                        onChange={set}
                        editable
                        max={cart.mostKilogramsOf(product.id)}
                        className="ml-auto h-10 flex-none rounded-[10px] border border-input bg-white min-[1100px]:h-11"
                        buttonClassName="size-10 text-primary min-[1100px]:size-11 min-[1100px]:text-[22px]"
                    />
                </div>
            ) : (
                <span className="text-[13px] font-semibold text-muted">{product.isAvailable ? bn.catalogue.soldOut : bn.catalogue.unavailable}</span>
            )}
        </div>
    );
}

/** The mockup's ladder of weights: each step's range and name, the Cart's own filled in. */
function StepCells({ steps, className }: { steps: PriceStep[]; className?: string }) {
    return steps.map((step, index) => (
        <div
            key={step.min}
            className={cn(
                'rounded-[8px] border text-center leading-[1.4]',
                step.current ? 'border-primary bg-primary text-white' : 'border-border bg-white text-ink',
                className,
            )}
        >
            {tierRange(step)}
            <br />
            <b className="text-[14px] font-bold">{stepName(index)}</b>
        </div>
    ));
}

function Summary({ cart, steps }: { cart: UseCart; steps: PriceStep[] | null }) {
    const taka = useTaka();
    const { priced, isEmpty } = cart;
    const note = deliveryNote(priced);

    return (
        <aside className="sticky top-[150px] hidden flex-col gap-4 rounded-[14px] border border-border bg-white p-6 md:flex">
            <span className="text-[20px] font-semibold text-ink">{bn.bulkOrder.summary}</span>
            <div className="flex flex-col gap-[10px]">
                <SummaryLine label={bn.bulkOrder.totalQuantity} value={bn.cart.kilogramsOf(toBanglaDigits(priced.totalKilograms))} large />
                <SummaryLine label={bn.bulkOrder.average} value={priced.averagePerKilogram === null ? taka(0) : taka(priced.averagePerKilogram)} large />
                <SummaryLine label={bn.bulkOrder.teaPrice} value={taka(priced.subtotal)} />
                <SummaryLine label={bn.bulkOrder.delivery} value={isEmpty ? bn.cart.noFigure : feeText(priced.deliveryCharge, taka)} accent />
                {note && <span className="text-right text-[12px] text-eyebrow">{note}</span>}
                <div className="flex items-baseline justify-between border-t border-divider pt-[14px]">
                    <span className="text-[16px] font-medium">{bn.bulkOrder.total}</span>
                    <b className="text-[30px] font-bold text-primary-dark">{taka(priced.total)}</b>
                </div>
                {priced.savings > 0 && <div className="text-right text-[14px] text-primary">{bn.bulkOrder.saving(taka(priced.savings))}</div>}
            </div>
            <div className="rounded-[10px] bg-green-50 px-[14px] py-3 text-[15px] leading-[1.6] font-semibold text-primary-dark">{nextDropText(priced, taka)}</div>
            {steps !== null && (
                <div className="grid grid-cols-3 gap-[6px]">
                    <StepCells steps={steps} className="px-1 py-[7px] text-[12px] leading-[1.45]" />
                </div>
            )}
            <ContinueButton disabled={isEmpty || cart.hasUnpackable || cart.hasOutsideLimit || cart.hasShort} className="w-full p-[15px] text-[16px]">
                {bn.bulkOrder.continue}
            </ContinueButton>
        </aside>
    );
}

function SummaryLine({ label, value, large = false, accent = false }: { label: string; value: string; large?: boolean; accent?: boolean }) {
    return (
        <div className={cn('flex justify-between', large ? 'text-[15px]' : 'text-[14px]')}>
            <span className="text-muted">{label}</span>
            <b className={cn('font-semibold', accent && 'text-primary')}>{value}</b>
        </div>
    );
}

/** On a phone: the next price drop, the total and the continue button, pinned to the bottom. */
function BottomBar({ cart }: { cart: UseCart }) {
    const taka = useTaka();
    const { priced, isEmpty } = cart;

    return (
        <div className="sticky bottom-0 z-20 flex flex-col gap-2 border-t border-border bg-white px-4 pt-[10px] pb-3 shadow-bottom-bar md:hidden">
            <span className="rounded-[6px] bg-green-50 px-2 py-[5px] text-center text-[13px] leading-[1.4] font-semibold text-primary-dark">{nextDropText(priced, taka)}</span>
            <div className="flex items-center gap-3">
                <div className="flex min-w-0 flex-1 flex-col">
                    <span className="text-[12px] text-muted">{bn.cart.barLine(toBanglaDigits(priced.totalKilograms))}</span>
                    <b className="text-[21px] leading-[1.3] font-bold text-ink">{taka(priced.total)}</b>
                </div>
                <ContinueButton disabled={isEmpty || cart.hasUnpackable || cart.hasOutsideLimit || cart.hasShort} className="flex-none px-[18px] py-[13px] text-[15px] whitespace-nowrap">
                    {bn.bulkOrder.continueShort}
                </ContinueButton>
            </div>
        </div>
    );
}

/** On to the Checkout; greyed and inert while the Cart is empty. */
function ContinueButton({ disabled, className, children }: { disabled: boolean; className: string; children: string }) {
    const { links } = usePage().props;

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={() => router.visit(links.checkout)}
            className={cn('cursor-pointer rounded-[10px] border-0 text-center font-semibold text-white disabled:cursor-not-allowed', disabled ? 'bg-disabled' : 'bg-primary hover:bg-primary-dark', className)}
        >
            {children}
        </button>
    );
}
