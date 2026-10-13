/**
 * The props every storefront response shares, as `HandleInertiaRequests`
 * sends them.
 */

import type { CartProduct } from '@/lib/cart';
import type { DeliveryBand } from '@/lib/pricing';
import type { CartPack } from '@/lib/retail-cart';

export type MenuLink = {
    label: string;
    href: string;
    external: boolean;
    note: string | null;
};

/**
 * One link of the header Menu, up to three levels deep: the bar, its
 * dropdown and a flyout. A Heading has no `href` and only opens its children.
 */
export type HeaderItem = {
    label: string;
    href: string | null;
    external: boolean;
    note: string | null;
    children: HeaderItem[];
};

export type FooterColumn = {
    heading: string;
    links: MenuLink[];
};

export type Reachable = {
    number: string;
    href: string;
};

export type Store = {
    name: string;
    tagline: string;
    logoUrl: string | null;
    hotline: Reachable | null;
    whatsapp: Reachable | null;
    email: string;
    address: string;
    hours: string;
};

export type Announcement = {
    pill: string | null;
    message: string;
};

export type Seo = {
    title: string;
    description: string;
    canonical: string;
    image: string | null;
    type: string;
    siteName: string;
};

export type SharedProps = {
    store: Store;
    announcement: Announcement | null;
    menus: {
        header: HeaderItem[];
        footer: {
            blurb: string;
            columns: FooterColumn[];
            bottomLinks: MenuLink[];
            /** The Merchant's Payment Banner, or null when none is uploaded. */
            paymentBanner: string | null;
        };
    };
    links: {
        bulkOrder: string;
        packetBasket: string;
        myOrders: string;
        checkout: string;
        search: string;
        searchSuggestions: string;
        signInCode: string;
        signIn: string;
        signOut: string;
        enquiry: string;
    };
    /** What the browser's Cart is priced from; prices and fees in minor units. */
    pricing: {
        deliveryBands: DeliveryBand[];
        /** Every published Product, in catalogue order, the unavailable ones among them. */
        products: CartProduct[];
    };
    /** What the browser's retail Cart is priced from (ADR-0034); money in minor units. */
    retailPricing: {
        deliveryCharge: number;
        /** The subtotal at which delivery is free; null for never. */
        freeDeliveryFrom: number | null;
        /** Every Pack of every published Product, in catalogue order; a Product with none sends none. */
        packs: CartPack[];
    };
    seo: Seo;
    /** How many minor units make one taka; every price is sent in minor units. */
    minorUnitsPerTaka: number;
    /** Where Moments are reported (`lib/tracking.ts`): the beacon, null where it would write nothing, and the Pixel's global. */
    tracking: {
        beacon: string | null;
        moments: string;
    };
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
