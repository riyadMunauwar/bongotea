/**
 * The storefront's mirror of retail pricing (`App\Support\Pricing\RetailPriceBook`,
 * ADR-0034), so every figure the packet basket shows is worked out as it changes.
 *
 * The server's figures are the only ones that count: this exists to display,
 * and Capture checks what it showed. The two are held to the same arithmetic
 * by the shared worked examples in `tests/Fixtures/Pricing/retail-examples.json`.
 * It owes nothing to the bulk Tiers or the bulk Delivery Zones, though it
 * reads its own zones with the same helpers (`lib/pricing.ts`, ADR-0039).
 * Money is in minor units, as the server sends it.
 */

import { deliveryKilogramsOf, gramsOfPackets } from './grams';
import { agreed, bandFor, chargeIn, type DeliveryBand, type DeliveryRange, type DeliveryZone, rangeOf, zoneFor } from './pricing';

export type RetailPriceBook = {
    /** The retail Delivery Zones, each with its threshold for free delivery. */
    deliveryZones: DeliveryZone[];
};

export type PackPrices = {
    id: number;
    /** What the Saving is measured from; the Pack Price never exceeds it. */
    mrp: number;
    price: number;
    /** What one Pack weighs, which the Delivery Band is read by. */
    grams: number;
};

export type RetailLine = {
    pack: PackPrices;
    count: number;
    /** A Price Override set by a User, kept through any Repricing. */
    overridePrice?: number | null;
};

export type PricedPackLine = {
    packId: number;
    count: number;
    mrp: number;
    packPrice: number;
    overridden: boolean;
    lineTotal: number;
    /** The MRP less the Pack Price, times the count. */
    savings: number;
};

export type PricedRetailCart = {
    lines: PricedPackLine[];
    packCount: number;
    /** The Packs' weight rounded up to the next whole kilogram; nothing for an empty Cart. */
    kilograms: number;
    subtotal: number;
    savings: number;
    /** Nothing while no District is known; see the range. */
    deliveryCharge: number;
    /** The zone's name; null while no District is known. */
    deliveryZone: string | null;
    /** Null for an empty Cart, and while no District is known. */
    deliveryBand: DeliveryBand | null;
    /** Null for an empty Cart. */
    deliveryRange: DeliveryRange | null;
    total: number;
    /** What the subtotal still lacks for free delivery; null once it is free, or where it never is; with no District, null unless every zone agrees. */
    amountToFreeDelivery: number | null;
};

function amountToFreeDeliveryIn(zone: DeliveryZone, subtotal: number): number | null {
    const from = zone.freeDeliveryFrom ?? null;

    return from === null || subtotal >= from ? null : from - subtotal;
}

/**
 * Every figure a packet basket shows: each line its Pack Price times its
 * count, and the Delivery Charge of the zone of the District, once there is
 * one, by the Packs' weight rounded up to the next kilogram.
 */
export function priceRetailCart(book: RetailPriceBook, lines: RetailLine[], district: string | null = null): PricedRetailCart {
    if (lines.some((line) => line.count < 0)) {
        throw new Error('A line cannot hold fewer than no Packs.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) < 0)) {
        throw new Error('A Price Override cannot be below nothing.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) > line.pack.mrp)) {
        throw new Error('A Price Override cannot be above its MRP.');
    }

    let packCount = 0;
    let grams = 0;
    let subtotal = 0;
    let savings = 0;

    const pricedLines = lines.map((line): PricedPackLine => {
        const overridePrice = line.overridePrice ?? null;
        const packPrice = overridePrice ?? line.pack.price;
        const lineSavings = (line.pack.mrp - packPrice) * line.count;

        packCount += line.count;
        grams += gramsOfPackets(line.count, line.pack.grams);
        subtotal += packPrice * line.count;
        savings += lineSavings;

        return {
            packId: line.pack.id,
            count: line.count,
            mrp: line.pack.mrp,
            packPrice,
            overridden: overridePrice !== null,
            lineTotal: packPrice * line.count,
            savings: lineSavings,
        };
    });

    const kilograms = deliveryKilogramsOf(grams);
    const zone = zoneFor(book.deliveryZones, district);
    const deliveryCharge = zone === null ? 0 : chargeIn(zone, kilograms, subtotal);

    return {
        lines: pricedLines,
        packCount,
        kilograms,
        subtotal,
        savings,
        deliveryCharge,
        deliveryZone: zone?.name ?? null,
        deliveryBand: zone === null ? null : bandFor(zone, kilograms),
        deliveryRange: rangeOf(book.deliveryZones, kilograms, subtotal),
        total: subtotal + deliveryCharge,
        amountToFreeDelivery: zone === null ? agreed(book.deliveryZones, (candidate) => amountToFreeDeliveryIn(candidate, subtotal)) : amountToFreeDeliveryIn(zone, subtotal),
    };
}
