import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { priceCart, type PricedCart, type ProductPrices } from './pricing';
import type { CartPack } from './retail-cart';
import { priceRetailCart, type PricedRetailCart } from './retail-pricing';
import { addedToCartId, addedToRetailCartId, momentId, reportMoment } from './tracking';

export type UseMoments = {
    /** A Product's own page was opened: reported as one Kilogram of it. */
    productViewed: (product: ProductPrices) => void;
    /** A Product went from none to some in the Cart; reported once per Product per Cart. */
    addedToCart: (product: ProductPrices, kilograms: number, cartWasEmpty: boolean) => void;
    /** The Checkout was opened with this Cart in it. */
    checkoutStarted: (priced: PricedCart) => void;
    /** A Pack went from none to some in the packet basket; reported once per Pack per basket. */
    packAdded: (pack: CartPack, count: number, basketWasEmpty: boolean) => void;
    /** The Checkout was opened for the packet basket, with these Packs in it. */
    retailCheckoutStarted: (priced: PricedRetailCart, packs: CartPack[]) => void;
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
        const priced = (product: ProductPrices, kilograms: number) => priceCart({ deliveryBands: pricing.deliveryBands }, [{ product, kilograms }]);

        return {
            productViewed: (product) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'product_viewed', momentId(), priced(product, 1))),
            addedToCart: (product, kilograms, cartWasEmpty) =>
                quietly(() => {
                    const id = addedToCartId(cartWasEmpty, product.id);

                    if (id !== null) {
                        reportMoment(tracking, minorUnitsPerTaka, 'added_to_cart', id, priced(product, kilograms));
                    }
                }),
            checkoutStarted: (cart) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'checkout_started', momentId(), cart)),
            packAdded: (pack, count, basketWasEmpty) =>
                quietly(() => {
                    const id = addedToRetailCartId(basketWasEmpty, pack.id);

                    if (id !== null) {
                        const priced = priceRetailCart({ deliveryCharge: retailPricing.deliveryCharge, freeDeliveryFrom: retailPricing.freeDeliveryFrom }, [{ pack, count }]);

                        reportMoment(tracking, minorUnitsPerTaka, 'added_to_cart', id, { cart: 'retail', priced, packs: [pack] });
                    }
                }),
            retailCheckoutStarted: (priced, packs) => quietly(() => reportMoment(tracking, minorUnitsPerTaka, 'checkout_started', momentId(), { cart: 'retail', priced, packs })),
        };
    }, [tracking, minorUnitsPerTaka, pricing, retailPricing]);
}
