/**
 * The storefront's mirror of retail pricing (`App\Support\Pricing\RetailPriceBook`,
 * ADR-0034), so every figure the packet basket shows is worked out as it changes.
 *
 * The server's figures are the only ones that count: this exists to display,
 * and Capture checks what it showed. The two are held to the same arithmetic
 * by the shared worked examples in `tests/Fixtures/Pricing/retail-examples.json`.
 * It owes nothing to the bulk Tiers or Delivery Bands (`lib/pricing.ts`).
 * Money is in minor units, as the server sends it.
 */

export type RetailPriceBook = {
    /** The one flat charge on a retail Cart. */
    deliveryCharge: number;
    /** The subtotal at which delivery is free; null for never. */
    freeDeliveryFrom: number | null;
};

export type PackPrices = {
    id: number;
    /** What the Saving is measured from; the Pack Price never exceeds it. */
    mrp: number;
    price: number;
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
    subtotal: number;
    savings: number;
    deliveryCharge: number;
    total: number;
    /** What the subtotal still lacks for free delivery; null once it is free, or where it never is. */
    amountToFreeDelivery: number | null;
};

/** Every figure a packet basket shows: each line its Pack Price times its count, and one flat charge. */
export function priceRetailCart(book: RetailPriceBook, lines: RetailLine[]): PricedRetailCart {
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
    let subtotal = 0;
    let savings = 0;

    const pricedLines = lines.map((line): PricedPackLine => {
        const overridePrice = line.overridePrice ?? null;
        const packPrice = overridePrice ?? line.pack.price;
        const lineSavings = (line.pack.mrp - packPrice) * line.count;

        packCount += line.count;
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

    const isFree = book.freeDeliveryFrom !== null && subtotal >= book.freeDeliveryFrom;
    const deliveryCharge = packCount === 0 || isFree ? 0 : book.deliveryCharge;

    return {
        lines: pricedLines,
        packCount,
        subtotal,
        savings,
        deliveryCharge,
        total: subtotal + deliveryCharge,
        amountToFreeDelivery: book.freeDeliveryFrom === null || isFree ? null : book.freeDeliveryFrom - subtotal,
    };
}
