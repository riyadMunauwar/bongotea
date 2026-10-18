import { tierRange } from '@/components/catalogue/tier-ladder';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import type { PriceStep } from '@/lib/cart';
import type { DeliveryRange, PricedCart } from '@/lib/pricing';
import type { PricedRetailCart } from '@/lib/retail-pricing';

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

/** What either kind of priced Cart says about its delivery (ADR-0039). */
type DeliveryFigures = {
    subtotal: number;
    deliveryCharge: number;
    /** Null while no District is known. */
    deliveryZone: string | null;
    /** Null for an empty Cart. */
    deliveryRange: DeliveryRange | null;
};

/** Whether the charge still depends on a District nobody has given: no zone known, and the zones disagree. */
export function dependsOnDistrict(priced: DeliveryFigures): boolean {
    return priced.deliveryZone === null && priced.deliveryRange !== null && priced.deliveryRange.least !== priced.deliveryRange.most;
}

/**
 * The Delivery Charge as a buyer may be told it: the zone's once a District
 * is known; before that the one figure every zone agrees on, or the range
 * "৳৭০–৳১৩০" — never one zone's figure as though it were the price.
 */
export function deliveryText(priced: DeliveryFigures, taka: Taka): string {
    if (priced.deliveryZone !== null || priced.deliveryRange === null) {
        return feeText(priced.deliveryCharge, taka);
    }

    const { least, most } = priced.deliveryRange;

    return least === most ? feeText(least, taka) : bn.cart.range(feeText(least, taka), taka(most));
}

/** The total with delivery, read the same way: a range while the charge still depends on a District. */
export function totalText(priced: DeliveryFigures, taka: Taka): string {
    if (priced.deliveryZone !== null || priced.deliveryRange === null) {
        return taka(priced.subtotal + priced.deliveryCharge);
    }

    const { least, most } = priced.deliveryRange;

    return least === most ? taka(priced.subtotal + least) : bn.cart.range(taka(priced.subtotal + least), taka(priced.subtotal + most));
}

/** Whether delivery is free wherever it is known to go: in the zone, or before a District in every zone. */
function isFreeDelivery(priced: DeliveryFigures): boolean {
    return priced.deliveryZone !== null ? priced.deliveryCharge === 0 : priced.deliveryRange?.most === 0;
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

    if (isFreeDelivery(priced)) {
        return bn.bulkOrder.freeDelivery;
    }

    if (priced.kilogramsToFreeDelivery !== null) {
        return bn.bulkOrder.toFreeDelivery(toBanglaDigits(priced.kilogramsToFreeDelivery));
    }

    return dependsOnDistrict(priced) ? bn.cart.deliveryByDistrict : null;
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

/** A Pack's name, which is its weight: "২৫০ গ্রাম", "১ কেজি", "১.৫ কেজি". */
export function packName(grams: number): string {
    return grams < 1000 ? bn.packs.grams(toBanglaDigits(grams)) : bn.packs.kilograms(toBanglaDigits(grams / 1000));
}

/** Under the retail Delivery Charge: whether it is free, or how much more would make it so. */
export function retailDeliveryNote(priced: PricedRetailCart, taka: Taka): string | null {
    if (priced.packCount > 0 && isFreeDelivery(priced)) {
        return bn.packs.freeDelivery;
    }

    if (priced.amountToFreeDelivery !== null) {
        return bn.packs.toFreeDelivery(taka(priced.amountToFreeDelivery));
    }

    return dependsOnDistrict(priced) ? bn.cart.deliveryByDistrict : null;
}
