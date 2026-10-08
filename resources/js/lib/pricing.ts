/**
 * The storefront's mirror of the pricing module (`App\Support\Pricing\PriceBook`,
 * ADR-0028), so every figure the Cart shows is worked out as it changes.
 *
 * The server's figures are the only ones that count: this exists to display,
 * and Capture checks what it showed. The two are held to the same arithmetic
 * by the shared worked examples in `tests/Fixtures/Pricing/examples.json`;
 * change a rule here and not there and a test fails. Money is in minor units,
 * as the server sends it, and every step is integer arithmetic so both land
 * on the same taka.
 */

export type DiscountType = 'percent' | 'fixed';

export type Tier = {
    key: string;
    min: number;
    /** Null for the last Tier, which is open-ended. */
    max: number | null;
};

export type DeliveryBand = {
    min: number;
    /** Null for the last band, which is open-ended. */
    max: number | null;
    fee: number;
};

export type PriceBook = {
    /** In order, covering every whole kilogram from one without gap or overlap. */
    tiers: Tier[];
    /** In order, covering every whole kilogram from one without gap or overlap. */
    deliveryBands: DeliveryBand[];
    /** What a percentage discount rounds to: one whole taka. */
    minorUnitsPerTaka: number;
};

export type ProductPrices = {
    id: number;
    regularPrice: number;
    discountType: DiscountType;
    /** Keyed by Tier key; a Tier the Product says nothing about is absent or null. */
    tierDiscounts: Record<string, number | null>;
};

export type CartLine = {
    product: ProductPrices;
    kilograms: number;
    /** A Price Override set by a User, kept through any Repricing. */
    overridePrice?: number | null;
};

export type Discount = {
    type: DiscountType;
    value: number;
};

export type TierPrice = {
    tier: Tier;
    price: number;
    /** Null where the price is the Regular Price. */
    discount: Discount | null;
};

export type PricedLine = {
    productId: number;
    kilograms: number;
    regularPrice: number;
    tierPrice: number;
    /** Null where the line pays the Regular Price or was overridden. */
    discount: Discount | null;
    overridden: boolean;
    lineTotal: number;
    /** Null at the top Tier; an overridden line keeps its price there too. */
    nextTierPrice: number | null;
};

export type PricedCart = {
    tier: Tier;
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
    /** Null at the top Tier. The saving counts only the Kilograms already in the Cart. */
    nextTier: { tier: Tier; kilogramsToGo: number; extraSaving: number } | null;
    /** Null once delivery is free, or where no free band lies ahead. */
    kilogramsToFreeDelivery: number | null;
};

function contains(range: { min: number; max: number | null }, kilograms: number): boolean {
    return kilograms >= range.min && (range.max === null || kilograms <= range.max);
}

/** Integer division of non-negative whole numbers, as PHP's `intdiv`. */
function divide(dividend: number, divisor: number): number {
    return Math.floor(dividend / divisor);
}

/**
 * The Regular Price less one Discount: a percentage rounds half-up to the
 * whole taka, a fixed amount is plain subtraction, neither below nothing.
 */
function applyDiscount(discount: Discount, regularPrice: number, minorUnitsPerTaka: number): number {
    if (discount.type === 'fixed') {
        return Math.max(0, regularPrice - discount.value);
    }

    const hundredths = regularPrice * (100 - discount.value);
    const hundredthsPerTaka = 100 * minorUnitsPerTaka;

    return Math.max(0, divide(hundredths + divide(hundredthsPerTaka, 2), hundredthsPerTaka) * minorUnitsPerTaka);
}

/**
 * A Product's price in every Tier, in order: the first is always the Regular
 * Price, and a Tier the Product names no Discount for charges the Tier below's.
 */
export function ladder(book: PriceBook, product: ProductPrices): TierPrice[] {
    let price = product.regularPrice;
    let discount: Discount | null = null;

    return book.tiers.map((tier, index) => {
        const value = index === 0 ? null : (product.tierDiscounts[tier.key] ?? null);

        if (value !== null) {
            const named: Discount = { type: product.discountType, value };
            price = applyDiscount(named, product.regularPrice, book.minorUnitsPerTaka);
            discount = value === 0 ? null : named;
        }

        return { tier, price, discount };
    });
}

/** An empty Cart sits in the first Tier, so it shows the Regular Prices. */
function tierIndexFor(book: PriceBook, totalKilograms: number): number {
    if (totalKilograms === 0) {
        return 0;
    }

    const index = book.tiers.findIndex((tier) => contains(tier, totalKilograms));

    if (index === -1) {
        throw new Error(`No Tier covers ${totalKilograms} kg.`);
    }

    return index;
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

/** Every figure a Cart shows, priced by the one Tier its total Kilograms fall in. */
export function priceCart(book: PriceBook, lines: CartLine[]): PricedCart {
    if (lines.some((line) => line.kilograms < 0)) {
        throw new Error('A line cannot hold fewer than no Kilograms.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) < 0)) {
        throw new Error('A Price Override cannot be below nothing.');
    }

    const totalKilograms = lines.reduce((sum, line) => sum + line.kilograms, 0);
    const tierIndex = tierIndexFor(book, totalKilograms);
    const nextTier = book.tiers[tierIndex + 1] ?? null;

    let subtotal = 0;
    let regularTotal = 0;
    let extraSaving = 0;

    const pricedLines = lines.map((line): PricedLine => {
        const steps = ladder(book, line.product);
        const overridePrice = line.overridePrice ?? null;
        const overridden = overridePrice !== null;
        const tierPrice = overridePrice ?? steps[tierIndex].price;
        const nextTierPrice = nextTier === null ? null : overridden ? tierPrice : steps[tierIndex + 1].price;

        subtotal += tierPrice * line.kilograms;
        regularTotal += line.product.regularPrice * line.kilograms;
        extraSaving += nextTierPrice === null ? 0 : (tierPrice - nextTierPrice) * line.kilograms;

        return {
            productId: line.product.id,
            kilograms: line.kilograms,
            regularPrice: line.product.regularPrice,
            tierPrice,
            discount: overridden ? null : steps[tierIndex].discount,
            overridden,
            lineTotal: tierPrice * line.kilograms,
            nextTierPrice,
        };
    });

    const band = deliveryBandFor(book, totalKilograms);
    const deliveryCharge = band?.fee ?? 0;

    return {
        tier: book.tiers[tierIndex],
        lines: pricedLines,
        totalKilograms,
        subtotal,
        savings: regularTotal - subtotal,
        deliveryCharge,
        deliveryBand: band,
        total: subtotal + deliveryCharge,
        averagePerKilogram: totalKilograms === 0 ? null : divide(2 * subtotal + totalKilograms, 2 * totalKilograms),
        nextTier: nextTier === null ? null : { tier: nextTier, kilogramsToGo: nextTier.min - totalKilograms, extraSaving },
        kilogramsToFreeDelivery: kilogramsToFreeDelivery(book, totalKilograms, band),
    };
}
