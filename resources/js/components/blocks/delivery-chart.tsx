import { usePage } from '@inertiajs/react';
import { feeText } from '@/components/cart/figures';
import { tierRange } from '@/components/catalogue/tier-ladder';
import { TruckIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useCart } from '@/lib/use-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { DeliveryChartProps } from '@/types/blocks';

/**
 * The Delivery Bands as a bar chart: one bar per band, as tall as its fee
 * against the dearest, the free band low and in gold, the band the Cart is
 * in filled and marked "আপনার কার্ট". The bands are the shared ones the Cart
 * is priced from, so the chart never disagrees with the summary.
 */
export function DeliveryChart({ heading, text, offersCashOnDelivery }: DeliveryChartProps) {
    const taka = useTaka();
    const { pricing } = usePage().props;
    const { priced } = useCart(usePage().props.startingCarts.bulk);
    const bands = pricing.deliveryBands;
    const dearest = Math.max(...bands.map((band) => band.fee));
    const free = bands.find((band) => band.fee === 0);
    const columns = { gridTemplateColumns: `repeat(${bands.length}, minmax(0, 1fr))` };

    if (bands.length === 0) {
        return null;
    }

    return (
        <section className="shell-container py-10 md:py-[72px]">
            <div className="grid overflow-hidden rounded-[20px] border border-border bg-white md:grid-cols-[minmax(0,.8fr)_minmax(0,1.6fr)]">
                <div className="flex flex-col justify-center gap-[14px] bg-night p-5 text-white md:p-7">
                    <span className="flex size-12 items-center justify-center rounded-[12px] bg-gold-accent/15 text-gold-accent">
                        <TruckIcon />
                    </span>
                    <div className="flex flex-col gap-[6px]">
                        <b className="text-[clamp(20px,2vw,24px)] leading-[1.3] font-bold">{heading ?? bn.delivery.heading}</b>
                        <span className="text-[14px] leading-[1.6] text-on-dark-sub">{text ?? bn.delivery.text}</span>
                    </div>
                    {free && (
                        <div className="flex items-center gap-[10px] rounded-[12px] bg-gold-accent px-[14px] py-3 text-night">
                            <b className="text-[22px] font-extrabold">{bn.cart.free}</b>
                            <span className="text-[13px] leading-[1.4] font-semibold">{bn.delivery.freeFrom(toBanglaDigits(free.min))}</span>
                        </div>
                    )}
                    <div className="flex flex-col gap-[6px] text-[13px] text-on-dark-sub">
                        <span>{bn.delivery.sameEverywhere}</span>
                        {offersCashOnDelivery && <span>{bn.delivery.cashOnDelivery}</span>}
                    </div>
                </div>

                <div className="flex flex-col gap-4 p-5 md:p-7">
                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                        <span className="text-[15px] font-semibold text-ink">{bn.delivery.byWeight}</span>
                        <span className="text-[13px] font-semibold text-primary">
                            {priced.deliveryBand === null
                                ? bn.delivery.cartEmpty
                                : bn.delivery.cartNow(toBanglaDigits(priced.totalKilograms), feeText(priced.deliveryCharge, taka))}
                        </span>
                    </div>
                    <div className="grid h-[170px] items-end gap-[6px] md:h-[200px] md:gap-[14px]" style={columns}>
                        {bands.map((band) => {
                            const isCurrent = priced.deliveryBand?.min === band.min;
                            const isFree = band.fee === 0;
                            const height = isFree ? 14 : Math.max(18, Math.round((band.fee / dearest) * 78));

                            return (
                                <div key={band.min} className="flex h-full flex-col items-center justify-end gap-[6px]">
                                    {isCurrent && (
                                        <span className="rounded-full bg-primary px-[7px] py-[2px] text-[10px] font-bold whitespace-nowrap text-white">{bn.delivery.yourCart}</span>
                                    )}
                                    <b className={cn('text-[14px] font-extrabold whitespace-nowrap md:text-[18px]', isFree ? 'text-gold-text' : isCurrent ? 'text-primary-dark' : 'text-ink')}>
                                        {feeText(band.fee, taka)}
                                    </b>
                                    <span
                                        className={cn(
                                            'block w-full rounded-t-[10px] rounded-b-[4px] transition-[height] duration-400',
                                            isFree ? 'bg-gold-accent' : isCurrent ? 'bg-primary' : 'bg-step-chip-line',
                                            isCurrent && 'shadow-[inset_0_0_0_2px_var(--color-primary-dark)]',
                                        )}
                                        style={{ height: `${height}%` }}
                                    />
                                </div>
                            );
                        })}
                    </div>
                    <div className="grid gap-[6px] border-t border-divider pt-[10px] md:gap-[14px]" style={columns}>
                        {bands.map((band) => (
                            <span
                                key={band.min}
                                className={cn(
                                    'text-center text-[11px] leading-[1.35] md:text-[13px]',
                                    priced.deliveryBand?.min === band.min ? 'font-bold text-primary-dark' : 'font-medium text-muted',
                                )}
                            >
                                {tierRange(band)}
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}
