import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { feeText } from '@/components/cart/figures';
import { tierRange } from '@/components/catalogue/tier-ladder';
import { TruckIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { bandFor, chargeIn } from '@/lib/pricing';
import { useCart } from '@/lib/use-cart';
import { useCurrentCarts } from '@/lib/use-current-carts';
import { useRetailCart } from '@/lib/use-retail-cart';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { DeliveryChartProps } from '@/types/blocks';

/**
 * The Delivery Zones of one kind as bar charts (ADR-0039): a tab per zone
 * where there is more than one, and in it one bar per band, as tall as its
 * fee against the dearest, the free band low and in gold, the band the
 * Current Cart of that kind is in filled and marked "আপনার কার্ট". The
 * zones are the shared ones the Cart is priced from, so the chart never
 * disagrees with the summary.
 */
export function DeliveryChart({ kind, heading, text, offersCashOnDelivery }: DeliveryChartProps) {
    const taka = useTaka();
    const { pricing, retailPricing } = usePage().props;
    const current = useCurrentCarts();
    const bulk = useCart(kind === 'bulk' ? (current.bulk?.id ?? null) : null).priced;
    const retail = useRetailCart(kind === 'retail' ? (current.retail?.id ?? null) : null).priced;
    const zones = kind === 'bulk' ? pricing.deliveryZones : retailPricing.deliveryZones;
    const [chosen, setChosen] = useState(0);
    const zone = zones[Math.min(chosen, zones.length - 1)];

    if (zone === undefined || zone.bands.length === 0) {
        return null;
    }

    const kilograms = kind === 'bulk' ? bulk.totalKilograms : retail.kilograms;
    const subtotal = kind === 'bulk' ? bulk.subtotal : retail.subtotal;
    const cartBand = bandFor(zone, kilograms);
    const bands = zone.bands;
    const dearest = Math.max(...bands.map((band) => band.fee));
    const freeBand = bands.find((band) => band.fee === 0);
    const freeFrom = zone.freeDeliveryFrom ?? null;
    const columns = { gridTemplateColumns: `repeat(${bands.length}, minmax(0, 1fr))` };

    return (
        <section className="shell-container py-10 md:py-[72px]">
            <div className="grid overflow-hidden rounded-[20px] border border-border bg-white md:grid-cols-[minmax(0,.8fr)_minmax(0,1.6fr)]">
                <div className="flex flex-col justify-center gap-[14px] bg-night p-5 text-white md:p-7">
                    <span className="flex size-12 items-center justify-center rounded-[12px] bg-gold-accent/15 text-gold-accent">
                        <TruckIcon />
                    </span>
                    <div className="flex flex-col gap-[6px]">
                        <b className="text-[clamp(20px,2vw,24px)] leading-[1.3] font-bold">{heading ?? bn.delivery.heading}</b>
                        <span className="text-[14px] leading-[1.6] text-on-dark-sub">{text ?? (kind === 'bulk' ? bn.delivery.text : bn.delivery.retailText)}</span>
                    </div>
                    {(freeBand || freeFrom !== null) && (
                        <div className="flex items-center gap-[10px] rounded-[12px] bg-gold-accent px-[14px] py-3 text-night">
                            <b className="text-[22px] font-extrabold">{bn.cart.free}</b>
                            <span className="text-[13px] leading-[1.4] font-semibold">
                                {freeBand ? bn.delivery.freeFrom(toBanglaDigits(freeBand.min)) : bn.delivery.retailFreeFrom(taka(freeFrom as number))}
                            </span>
                        </div>
                    )}
                    <div className="flex flex-col gap-[6px] text-[13px] text-on-dark-sub">
                        <span>{zones.length === 1 ? bn.delivery.sameEverywhere : bn.delivery.byZone}</span>
                        {offersCashOnDelivery && <span>{bn.delivery.cashOnDelivery}</span>}
                    </div>
                </div>

                <div className="flex flex-col gap-4 p-5 md:p-7">
                    {zones.length > 1 && (
                        <div role="tablist" className="flex flex-wrap gap-2">
                            {zones.map((candidate, index) => (
                                <button
                                    key={candidate.name}
                                    type="button"
                                    role="tab"
                                    aria-selected={candidate === zone}
                                    onClick={() => setChosen(index)}
                                    className={cn(
                                        'cursor-pointer rounded-full border px-[14px] py-[6px] text-[13px] font-semibold',
                                        candidate === zone ? 'border-primary bg-primary text-white' : 'border-border bg-white text-ink',
                                    )}
                                >
                                    {candidate.name}
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                        <span className="text-[15px] font-semibold text-ink">{bn.delivery.byWeight}</span>
                        <span className="text-[13px] font-semibold text-primary">
                            {cartBand === null ? bn.delivery.cartEmpty : bn.delivery.cartNow(toBanglaDigits(kilograms), feeText(chargeIn(zone, kilograms, subtotal), taka))}
                        </span>
                    </div>
                    <div className="grid h-[170px] items-end gap-[6px] md:h-[200px] md:gap-[14px]" style={columns}>
                        {bands.map((band) => {
                            const isCurrent = cartBand?.min === band.min;
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
                                className={cn('text-center text-[11px] leading-[1.35] md:text-[13px]', cartBand?.min === band.min ? 'font-bold text-primary-dark' : 'font-medium text-muted')}
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
