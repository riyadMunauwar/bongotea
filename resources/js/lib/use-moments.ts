import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { priceCart, type PricedCart, type ProductPrices } from './pricing';
import type { CartPack } from './retail-cart';
import { priceRetailCart, type PricedRetailCart } from './retail-pricing';
import { addedToCartId, addedToRetailCartId, momentId, reportMoment, type MomentCart } from './tracking';

export type UseMoments = {
    /** A Product's own page was opened: reported as one Kilogram of it. */
    productViewed: (product: ProductPrices) => void;
    /** A Product went from none to some in a bulk Cart; reported once per Product per Cart's Contents, whichever Pack came first. */
    addedToCart: (product: ProductPrices, kilograms: number, cartWasEmpty: boolean, from: MomentCart) => void;
    /** The Checkout was opened for this bulk Cart, with these figures. */
    checkoutStarted: (priced: PricedCart, from: MomentCart) => void;
    /** A Pack went from none to some in a retail Cart; reported once per Pack per Cart's Contents. */
    packAdded: (pack: CartPack, count: number, basketWasEmpty: boolean, from: MomentCart) => void;
    /** The Checkout was opened for this retail Cart, with these Packs in it. */
    retailCheckoutStarted: (priced: PricedRetailCart, packs: CartPack[], from: MomentCart) => void;
};

/** Runs a report and swallows whatever it throws: reporting is never a reason for a page to fail. */
function quietly(report: () => void): void {
    try {
        report();
    } catch {
        // Lost to tracking, and nothing else.
    }
}

/**
 * The storefront Moments a page reports, each priced the way the server
 * prices its copy: what was named, as a Cart of its own.
 */
export function useMoments(): UseMoments {
    const { tracking, minorUnitsPerTaka, pricing, retailPricing } = usePage().props;

    return useMemo(() => {
        const priced = (product: ProductPrices, kilograms: number) => priceCart({ deliveryZones: pricing.deliveryZones }, [{ product, kilograms }]);

        return {
            productViewed: (product) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'product_viewed', momentId(), priced(product, 1))),
            addedToCart: (product, kilograms, cartWasEmpty, from) =>
                quietly(() => {
                    const id = addedToCartId(from.id, cartWasEmpty, product.id);

                    if (id !== null) {
                        reportMoment(tracking, minorUnitsPerTaka, 'added_to_cart', id, priced(product, kilograms), from);
                    }
                }),
            checkoutStarted: (cart, from) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'checkout_started', momentId(), cart, from)),
            packAdded: (pack, count, basketWasEmpty, from) =>
                quietly(() => {
                    const id = addedToRetailCartId(from.id, basketWasEmpty, pack.id);

                    if (id !== null) {
                        const priced = priceRetailCart({ deliveryZones: retailPricing.deliveryZones }, [{ pack, count }]);

                        reportMoment(tracking, minorUnitsPerTaka, 'added_to_cart', id, { cart: 'retail', priced, packs: [pack] }, from);
                    }
                }),
            retailCheckoutStarted: (priced, packs, from) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'checkout_started', momentId(), { cart: 'retail', priced, packs }, from)),
        };
    }, [tracking, minorUnitsPerTaka, pricing, retailPricing]);
}
