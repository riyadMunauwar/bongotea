/**
 * The storefront's mirror of the pricing module (`App\Support\Pricing\PriceBook`,
 * ADR-0031), so every figure the Cart shows is worked out as it changes.
 *
 * The server's figures are the only ones that count: this exists to display,
 * and Capture checks what it showed. The two are held to the same arithmetic
 * by the shared worked examples in `tests/Fixtures/Pricing/examples.json`;
 * change a rule here and not there and a test fails. Money is in minor units,
 * as the server sends it, and every step is integer arithmetic so both land
 * on the same taka.
 */

export type KilogramRange = {
    min: number;
    /** Null for the last range, which is open-ended. */
    max: number | null;
};

/** One of a Product's own ranges and the price it charges there. */
export type Tier = KilogramRange & { price: number };

export type DeliveryBand = KilogramRange & { fee: number };

export type PriceBook = {
    /** In order, covering every whole kilogram from one without gap or overlap. */
    deliveryBands: DeliveryBand[];
};

export type ProductPrices = {
    id: number;
    /** In order, covering every whole kilogram from one without gap or overlap, never rising in price. */
    tiers: Tier[];
};

export type CartLine = {
    product: ProductPrices;
    kilograms: number;
    /** A Price Override set by a User, kept through any Repricing. */
    overridePrice?: number | null;
};

export type PricedLine = {
    productId: number;
    kilograms: number;
    regularPrice: number;
    tierPrice: number;
    /** The range the price came from; null on an overridden line. */
    tier: KilogramRange | null;
    overridden: boolean;
    lineTotal: number;
};

export type PriceDrop = {
    atKilograms: number;
    kilogramsToGo: number;
    /** Counts only the Kilograms already in the Cart. */
    extraSaving: number;
};

export type PricedCart = {
    lines: PricedLine[];
    totalKilograms: number;
    subtotal: number;
    savings: number;
    deliveryCharge: number;
    /** Null for an empty Cart. */
    deliveryBand: DeliveryBand | null;
    total: number;
    /** The subtotal per Kilogram, rounded half-up to the minor unit; null for an empty Cart. */
    averagePerKilogram: number | null;
    /** The nearest weight at which a line already in the Cart gets cheaper; null where none does. */
    nextPriceDrop: PriceDrop | null;
    /** Null once delivery is free, or where no free band lies ahead. */
    kilogramsToFreeDelivery: number | null;
};

function contains(range: KilogramRange, kilograms: number): boolean {
    return kilograms >= range.min && (range.max === null || kilograms <= range.max);
}

/** Integer division of non-negative whole numbers, as PHP's `intdiv`. */
function divide(dividend: number, divisor: number): number {
    return Math.floor(dividend / divisor);
}

/** The Product's Tier for the Cart's weight; an empty Cart sits in the first, so it shows the Regular Price. */
export function tierFor(product: ProductPrices, totalKilograms: number): Tier {
    if (totalKilograms === 0) {
        return product.tiers[0];
    }

    const tier = product.tiers.find((candidate) => contains(candidate, totalKilograms));

    if (tier === undefined) {
        throw new Error(`No Tier of Product ${product.id} covers ${totalKilograms} kg.`);
    }

    return tier;
}

/** The first Tier above the Cart's weight that is cheaper than the one it is in, or null where none is. */
function nextDropAbove(product: ProductPrices, totalKilograms: number): Tier | null {
    const current = tierFor(product, totalKilograms);

    return product.tiers.find((tier) => tier.min > totalKilograms && tier.price < current.price) ?? null;
}

/**
 * The nearest weight at which a line already holding Kilograms gets cheaper,
 * with the saving at that weight across every line. Overridden lines and
 * empty lines set no weight.
 */
function nextPriceDrop(lines: CartLine[], totalKilograms: number): PriceDrop | null {
    let atKilograms: number | null = null;

    for (const line of lines) {
        if ((line.overridePrice ?? null) !== null || line.kilograms === 0) {
            continue;
        }

        const drop = nextDropAbove(line.product, totalKilograms);

        if (drop !== null && (atKilograms === null || drop.min < atKilograms)) {
            atKilograms = drop.min;
        }
    }

    if (atKilograms === null) {
        return null;
    }

    const at = atKilograms;
    const extraSaving = lines
        .filter((line) => (line.overridePrice ?? null) === null)
        .reduce((sum, line) => sum + (tierFor(line.product, totalKilograms).price - tierFor(line.product, at).price) * line.kilograms, 0);

    return { atKilograms: at, kilogramsToGo: at - totalKilograms, extraSaving };
}

function deliveryBandFor(book: PriceBook, totalKilograms: number): DeliveryBand | null {
    if (totalKilograms === 0) {
        return null;
    }

    const band = book.deliveryBands.find((candidate) => contains(candidate, totalKilograms));

    if (band === undefined) {
        throw new Error(`No Delivery Band covers ${totalKilograms} kg.`);
    }

    return band;
}

function kilogramsToFreeDelivery(book: PriceBook, totalKilograms: number, band: DeliveryBand | null): number | null {
    if (band !== null && band.fee === 0) {
        return null;
    }

    const free = book.deliveryBands.find((candidate) => candidate.fee === 0 && candidate.min > totalKilograms);

    return free === undefined ? null : free.min - totalKilograms;
}

/** Every figure a Cart shows, each line priced from its own Product's Tier for the whole Cart's Kilograms. */
export function priceCart(book: PriceBook, lines: CartLine[]): PricedCart {
    if (lines.some((line) => line.kilograms < 0)) {
        throw new Error('A line cannot hold fewer than no Kilograms.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) < 0)) {
        throw new Error('A Price Override cannot be below nothing.');
    }

    const totalKilograms = lines.reduce((sum, line) => sum + line.kilograms, 0);

    let subtotal = 0;
    let regularTotal = 0;

    const pricedLines = lines.map((line): PricedLine => {
        const overridePrice = line.overridePrice ?? null;
        const tier = overridePrice === null ? tierFor(line.product, totalKilograms) : null;
        const tierPrice = tier === null ? (overridePrice as number) : tier.price;
        const regularPrice = line.product.tiers[0].price;

        subtotal += tierPrice * line.kilograms;
        regularTotal += regularPrice * line.kilograms;

        return {
            productId: line.product.id,
            kilograms: line.kilograms,
            regularPrice,
            tierPrice,
            tier: tier === null ? null : { min: tier.min, max: tier.max },
            overridden: tier === null,
            lineTotal: tierPrice * line.kilograms,
        };
    });

    const band = deliveryBandFor(book, totalKilograms);
    const deliveryCharge = band?.fee ?? 0;

    return {
        lines: pricedLines,
        totalKilograms,
        subtotal,
        savings: regularTotal - subtotal,
        deliveryCharge,
        deliveryBand: band,
        total: subtotal + deliveryCharge,
        averagePerKilogram: totalKilograms === 0 ? null : divide(2 * subtotal + totalKilograms, 2 * totalKilograms),
        nextPriceDrop: nextPriceDrop(lines, totalKilograms),
        kilogramsToFreeDelivery: kilogramsToFreeDelivery(book, totalKilograms, band),
    };
}
