/**
 * The storefront's half of reporting the Moments only a browser witnesses
 * (`.ai/rules/tracking.md`): each one is told to the Pixel the root document
 * carries (`TrackingSnippet::forStorefront()`) and posted to the beacon under
 * the same deduplication id, so the Provider counts one event.
 *
 * The Pixel is absent where the store reports nowhere, or where the consent
 * hook held it, and the beacon address is null where the store would write
 * nothing; both are simply skipped. Nothing here may throw into the page:
 * reporting is never a reason for buying to fail.
 */

import { router } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { storage } from './cart';
import type { PricedCart } from './pricing';
import type { CartPack } from './retail-cart';
import type { PricedRetailCart } from './retail-pricing';

export type StorefrontMoment = 'visit' | 'product_viewed' | 'added_to_cart' | 'checkout_started';

export type Tracking = SharedProps['tracking'];

/**
 * What the Pixel's functions report as what was being bought: taka, and
 * Product ids as strings, as the server copy has them, and which Cart it came
 * from. A Pack is reported as its Product, with its weight as the variant
 * (ADR-0034).
 */
export type MomentSelection = {
    value: number;
    count: number;
    cart: 'bulk' | 'retail';
    lines: { id: string; quantity: number; unitPrice: number; variant?: string }[];
};

/** A Moment about the packet basket, or some of it: the Packs priced, and the Packs themselves. */
export type RetailMoment = { cart: 'retail'; priced: PricedRetailCart; packs: CartPack[] };

/** Which Products this browser's Cart has reported Added to Cart for, and the Cart they were added to. */
export type CartMoments = { cart: string; reported: number[] };

const CART_MOMENTS_KEY = 'bongotea.cart-moments';

/** The packet basket's own record, so a Pack and a Product of the same id never stand in for each other. */
const RETAIL_CART_MOMENTS_KEY = 'bongotea.retail-cart-moments';

/**
 * Whether a Product going from none to some is an Added to Cart: once per
 * Product per Cart. A Cart begins when the first Product goes into an empty
 * one, so an emptied Cart — ordered or cleared — starts afresh.
 */
export function cartMomentFor(record: CartMoments | null, cartWasEmpty: boolean, productId: number, freshCartId: string): { record: CartMoments; reports: boolean } {
    const current = cartWasEmpty || record === null ? { cart: freshCartId, reported: [] } : record;

    if (current.reported.includes(productId)) {
        return { record: current, reports: false };
    }

    return { record: { cart: current.cart, reported: [...current.reported, productId] }, reports: true };
}

/** A priced Cart, or one Product priced as a Cart of its own, as the Pixel reports it. */
export function momentSelection(priced: PricedCart, minorUnitsPerTaka: number): MomentSelection {
    return {
        value: priced.subtotal / minorUnitsPerTaka,
        count: priced.totalKilograms,
        cart: 'bulk',
        lines: priced.lines.map((line) => ({ id: String(line.productId), quantity: line.kilograms, unitPrice: line.tierPrice / minorUnitsPerTaka })),
    };
}

/** A Pack's weight as the server names it (`Pack::nameFor()`): "250 g", "1 kg", "1.5 kg". */
export function packVariant(grams: number): string {
    return grams < 1000 ? `${grams} g` : `${Number((grams / 1000).toFixed(3))} kg`;
}

/** Packs priced as the Pixel reports them: each as its Product, the count its quantity, the Pack Price its price, the weight its variant. */
export function retailMomentSelection({ priced, packs }: RetailMoment, minorUnitsPerTaka: number): MomentSelection {
    const byId = new Map(packs.map((pack) => [pack.id, pack]));

    return {
        value: priced.subtotal / minorUnitsPerTaka,
        count: priced.packCount,
        cart: 'retail',
        lines: priced.lines.map((line) => {
            const pack = byId.get(line.packId);

            return {
                id: String(pack?.productId ?? line.packId),
                quantity: line.count,
                unitPrice: line.packPrice / minorUnitsPerTaka,
                ...(pack === undefined ? {} : { variant: packVariant(pack.grams) }),
            };
        }),
    };
}

/** What the beacon is told the packet basket held: Packs and counts, never a price. */
export function retailBeaconItems(priced: PricedRetailCart): string {
    return priced.lines.map((line) => `${line.packId}:${line.count}`).join(',');
}

/** What the beacon is told was being bought: Products and Kilograms, never a price — the server prices them itself. */
export function beaconItems(priced: PricedCart): string {
    return priced.lines.map((line) => `${line.productId}:${line.kilograms}`).join(',');
}

/** A deduplication id for one Moment, unique enough that two never collide. */
export function momentId(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    }
}

/**
 * Tells the Pixel and the beacon that a Moment happened. `priced` is what it
 * was about, for every Moment but a Visit: the bulk Cart's figures, or a
 * `RetailMoment` for the packet basket.
 */
export function reportMoment(tracking: Tracking, minorUnitsPerTaka: number, moment: StorefrontMoment, id: string, priced: PricedCart | RetailMoment | null = null): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const pixel = (window as unknown as Record<string, Partial<Record<StorefrontMoment, (id: string, selection?: MomentSelection) => void>> | undefined>)[tracking.moments];

        pixel?.[moment]?.(id, priced === null ? undefined : isRetail(priced) ? retailMomentSelection(priced, minorUnitsPerTaka) : momentSelection(priced, minorUnitsPerTaka));
    } catch {
        // The Pixel is somebody else's script; its failing is not the page's.
    }

    if (tracking.beacon === null) {
        return;
    }

    const query = new URLSearchParams({ moment, id });

    if (priced !== null && isRetail(priced)) {
        query.set('cart', 'retail');
        query.set('items', retailBeaconItems(priced.priced));
    } else if (priced !== null) {
        query.set('items', beaconItems(priced));
    }

    try {
        navigator.sendBeacon(`${tracking.beacon}?${query}`);
    } catch {
        // A browser with no beacon loses the server copy and nothing else.
    }
}

/**
 * Reports a Visit for every page the storefront shows: the first load and
 * each visit inside it, since one document serves them all. A reload of the
 * same address in place — a partial reload, a refreshed price list — is the
 * page already counted, not a new one.
 */
export function reportVisits(): void {
    let counted: string | null = null;

    router.on('navigate', (event) => {
        const { url, props } = event.detail.page;

        if (url === counted) {
            return;
        }

        counted = url;
        reportMoment(props.tracking, props.minorUnitsPerTaka, 'visit', momentId());
    });
}

function isRetail(priced: PricedCart | RetailMoment): priced is RetailMoment {
    return 'cart' in priced && priced.cart === 'retail';
}

function readCartMoments(key: string = CART_MOMENTS_KEY): CartMoments | null {
    try {
        const stored: unknown = JSON.parse(storage()?.getItem(key) ?? 'null');

        if (stored !== null && typeof stored === 'object' && typeof (stored as CartMoments).cart === 'string' && Array.isArray((stored as CartMoments).reported)) {
            return stored as CartMoments;
        }
    } catch {
        // Unreadable is the same as never written.
    }

    return null;
}

/**
 * Asked as a Product goes from none to some in the Cart: whether that is an
 * Added to Cart, and if so the deduplication id it is reported under — the
 * same for one Product in one Cart, so a second report of it is one event at
 * the Provider and one row in the outbox.
 */
export function addedToCartId(cartWasEmpty: boolean, productId: number): string | null {
    const { record, reports } = cartMomentFor(readCartMoments(), cartWasEmpty, productId, momentId());

    try {
        storage()?.setItem(CART_MOMENTS_KEY, JSON.stringify(record));
    } catch {
        // Kept for nobody; at worst the Product is reported again in this Cart.
    }

    return reports ? `${record.cart}-${productId}` : null;
}

/**
 * Asked as a Pack goes from none to some in the packet basket: whether that
 * is an Added to Cart — once per Pack per basket, however many follow it —
 * and if so its deduplication id, kept apart from the bulk Cart's.
 */
export function addedToRetailCartId(basketWasEmpty: boolean, packId: number): string | null {
    const { record, reports } = cartMomentFor(readCartMoments(RETAIL_CART_MOMENTS_KEY), basketWasEmpty, packId, momentId());

    try {
        storage()?.setItem(RETAIL_CART_MOMENTS_KEY, JSON.stringify(record));
    } catch {
        // Kept for nobody; at worst the Pack is reported again in this basket.
    }

    return reports ? `${record.cart}-pack-${packId}` : null;
}
