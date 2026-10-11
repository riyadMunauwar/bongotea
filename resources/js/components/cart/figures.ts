import { tierRange } from '@/components/catalogue/tier-ladder';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import type { PriceStep } from '@/lib/cart';
import type { PricedCart } from '@/lib/pricing';

/**
 * The Cart's figures as sentences, shared by every view of it so the header,
 * the Bulk Order summary and the calculator never word one figure two ways.
 * `taka` is `useTaka()`'s formatter.
 */

type Taka = (minorUnits: number) => string;

/** A Delivery Charge: "ফ্রি" when there is none. */
export function feeText(fee: number, taka: Taka): string {
    return fee === 0 ? bn.cart.free : taka(fee);
}

/** "আর ৩ কেজি নিলে আরও ৳১২০ সাশ্রয়", or that nothing gets cheaper from here. */
export function nextDropText(priced: PricedCart, taka: Taka): string {
    if (priced.totalKilograms === 0) {
        return bn.bulkOrder.emptyHint;
    }

    const drop = priced.nextPriceDrop;

    return drop === null ? bn.cart.cheapest : bn.cart.nextDrop(toBanglaDigits(drop.kilogramsToGo), taka(drop.extraSaving));
}

/** Under the Delivery Charge: whether it is free, or how far free delivery is. */
export function deliveryNote(priced: PricedCart): string | null {
    if (priced.totalKilograms === 0) {
        return bn.bulkOrder.deliveryByWeight;
    }

    if (priced.deliveryCharge === 0) {
        return bn.bulkOrder.freeDelivery;
    }

    return priced.kilogramsToFreeDelivery === null ? null : bn.bulkOrder.toFreeDelivery(toBanglaDigits(priced.kilogramsToFreeDelivery));
}

/**
 * What a Tier Price takes off the MRP, per Kilogram: "৳৮০ ছাড় (১৬%)". The share
 * is rounded down so it never claims more than is taken off, and left out when
 * that leaves nothing. Null for a price at the MRP, which takes nothing off.
 */
export function discountLabel(mrp: number, tierPrice: number, taka: Taka): string | null {
    const off = mrp - tierPrice;

    return off <= 0 ? null : bn.catalogue.offMrp(taka(off), discountPercent(mrp, tierPrice));
}

/** The share of the MRP a Tier Price takes off, rounded down, in Bangla digits; null when that is nothing. */
export function discountPercent(mrp: number, tierPrice: number): string | null {
    const percent = mrp > 0 ? Math.floor(((mrp - tierPrice) * 100) / mrp) : 0;

    return percent <= 0 ? null : toBanglaDigits(percent);
}

/** A price step's name in the ladder of weights: ধাপ ১, ধাপ ২, ধাপ ৩… */
export function stepName(index: number): string {
    return bn.cart.step(toBanglaDigits(index + 1));
}

/** The step the Cart is in, as a range, or null for an empty Cart. */
export function currentStepRange(steps: PriceStep[]): string | null {
    const current = steps.find((step) => step.current);

    return current === undefined ? null : tierRange(current);
}
