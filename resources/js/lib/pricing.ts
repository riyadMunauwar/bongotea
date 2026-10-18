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

/**
 * A Delivery Zone (ADR-0039): the Districts it covers and the bands a parcel
 * to any of them is charged from. Exactly one zone of each kind is
 * everywhere else, covering every District no other zone lists.
 */
export type DeliveryZone = {
    name: string;
    /** As the store stores them; empty for the everywhere-else zone. */
    districts: string[];
    everywhereElse: boolean;
    /** In order, covering every whole kilogram from one without gap or overlap. */
    bands: DeliveryBand[];
    /** The subtotal from which delivery here is free; null or absent for never. A bulk zone has none. */
    freeDeliveryFrom?: number | null;
};

/** The least and the most a Cart could pay across every zone, shown before a District is known. */
export type DeliveryRange = { least: number; most: number };

export type PriceBook = {
    deliveryZones: DeliveryZone[];
};

export type ProductPrices = {
    id: number;
    /** What every Saving is measured from; no Tier Price exceeds it. */
    mrp: number;
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
    mrp: number;
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
    /** Nothing while no District is known; see the range. */
    deliveryCharge: number;
    /** The zone's name; null while no District is known. */
    deliveryZone: string | null;
    /** Null for an empty Cart, and while no District is known. */
    deliveryBand: DeliveryBand | null;
    /** Null for an empty Cart. */
    deliveryRange: DeliveryRange | null;
    total: number;
    /** The subtotal per Kilogram, rounded half-up to the minor unit; null for an empty Cart. */
    averagePerKilogram: number | null;
    /** The nearest weight at which a line already in the Cart gets cheaper; null where none does. */
    nextPriceDrop: PriceDrop | null;
    /** Null once delivery is free, or where no free band lies ahead; with no District, null unless every zone agrees. */
    kilogramsToFreeDelivery: number | null;
};

function contains(range: KilogramRange, kilograms: number): boolean {
    return kilograms >= range.min && (range.max === null || kilograms <= range.max);
}

/** Integer division of non-negative whole numbers, as PHP's `intdiv`. */
function divide(dividend: number, divisor: number): number {
    return Math.floor(dividend / divisor);
}

/** The Product's Tier for the Cart's weight; an empty Cart sits in the first. */
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
export function nextDropAbove(product: ProductPrices, totalKilograms: number): Tier | null {
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

/** The zone a District is delivered under; null while no District is known. */
export function zoneFor(zones: DeliveryZone[], district: string | null): DeliveryZone | null {
    if (district === null || district === '') {
        return null;
    }

    const zone = zones.find((candidate) => candidate.districts.includes(district)) ?? zones.find((candidate) => candidate.everywhereElse);

    if (zone === undefined) {
        throw new Error('No Delivery Zone is everywhere else.');
    }

    return zone;
}

/** Null for an empty Cart: no band starts below one kilogram. */
export function bandFor(zone: DeliveryZone, kilograms: number): DeliveryBand | null {
    if (kilograms === 0) {
        return null;
    }

    const band = zone.bands.find((candidate) => contains(candidate, kilograms));

    if (band === undefined) {
        throw new Error(`No Delivery Band of ${zone.name} covers ${kilograms} kg.`);
    }

    return band;
}

/** What a Cart of this weight and subtotal pays in a zone, its threshold reached or not; nothing for an empty one. */
export function chargeIn(zone: DeliveryZone, kilograms: number, subtotal = 0): number {
    const band = bandFor(zone, kilograms);
    const isFree = (zone.freeDeliveryFrom ?? null) !== null && subtotal >= (zone.freeDeliveryFrom as number);

    return band === null || isFree ? 0 : band.fee;
}

/** The least and the most across every zone; null for an empty Cart. */
export function rangeOf(zones: DeliveryZone[], kilograms: number, subtotal = 0): DeliveryRange | null {
    if (kilograms === 0) {
        return null;
    }

    const charges = zones.map((zone) => chargeIn(zone, kilograms, subtotal));

    return { least: Math.min(...charges), most: Math.max(...charges) };
}

/** What every zone answers alike, or null where they differ. */
export function agreed(zones: DeliveryZone[], ask: (zone: DeliveryZone) => number | null): number | null {
    const answers = zones.map(ask);

    return answers.every((answer) => answer === answers[0]) ? (answers[0] ?? null) : null;
}

function kilogramsToFreeDeliveryIn(zone: DeliveryZone, totalKilograms: number): number | null {
    if (bandFor(zone, totalKilograms)?.fee === 0) {
        return null;
    }

    const free = zone.bands.find((candidate) => candidate.fee === 0 && candidate.min > totalKilograms);

    return free === undefined ? null : free.min - totalKilograms;
}

/**
 * Every figure a Cart shows, each line priced from its own Product's Tier for
 * the whole Cart's Kilograms, and the Delivery Charge from the zone of the
 * District, once there is one.
 */
export function priceCart(book: PriceBook, lines: CartLine[], district: string | null = null): PricedCart {
    if (lines.some((line) => line.kilograms < 0)) {
        throw new Error('A line cannot hold fewer than no Kilograms.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) < 0)) {
        throw new Error('A Price Override cannot be below nothing.');
    }

    if (lines.some((line) => (line.overridePrice ?? 0) > line.product.mrp)) {
        throw new Error('A Price Override cannot be above its MRP.');
    }

    const totalKilograms = lines.reduce((sum, line) => sum + line.kilograms, 0);

    let subtotal = 0;
    let mrpTotal = 0;

    const pricedLines = lines.map((line): PricedLine => {
        const overridePrice = line.overridePrice ?? null;
        const tier = overridePrice === null ? tierFor(line.product, totalKilograms) : null;
        const tierPrice = tier === null ? (overridePrice as number) : tier.price;

        subtotal += tierPrice * line.kilograms;
        mrpTotal += line.product.mrp * line.kilograms;

        return {
            productId: line.product.id,
            kilograms: line.kilograms,
            mrp: line.product.mrp,
            tierPrice,
            tier: tier === null ? null : { min: tier.min, max: tier.max },
            overridden: tier === null,
            lineTotal: tierPrice * line.kilograms,
        };
    });

    const zone = zoneFor(book.deliveryZones, district);
    const band = zone === null ? null : bandFor(zone, totalKilograms);
    const deliveryCharge = band?.fee ?? 0;

    return {
        lines: pricedLines,
        totalKilograms,
        subtotal,
        savings: mrpTotal - subtotal,
        deliveryCharge,
        deliveryZone: zone?.name ?? null,
        deliveryBand: band,
        deliveryRange: rangeOf(book.deliveryZones, totalKilograms),
        total: subtotal + deliveryCharge,
        averagePerKilogram: totalKilograms === 0 ? null : divide(2 * subtotal + totalKilograms, 2 * totalKilograms),
        nextPriceDrop: nextPriceDrop(lines, totalKilograms),
        kilogramsToFreeDelivery:
            zone === null ? agreed(book.deliveryZones, (candidate) => kilogramsToFreeDeliveryIn(candidate, totalKilograms)) : kilogramsToFreeDeliveryIn(zone, totalKilograms),
    };
}
