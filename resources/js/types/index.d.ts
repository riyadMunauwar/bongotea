/**
 * The props every storefront response shares, as `HandleInertiaRequests`
 * sends them.
 */

import type { CartProduct } from '@/lib/cart';
import type { DeliveryZone } from '@/lib/pricing';
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

export type CartKind = 'bulk' | 'retail';

/** A Cart a Customer may be offered: switched on, not deleted, and on a published Page. */
export type OpenCart = {
    id: number;
    name: string;
    kind: CartKind;
    href: string;
    sortOrder: number;
};

/** An open Cart a header button opens. */
export type HeaderCart = { id: number; name: string; href: string };

/** The Cart a Block draws, open or closed; a closed one is drawn closed. */
export type BlockCart = {
    id: number;
    name: string;
    kind: CartKind;
    isOpen: boolean;
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
        /** The bulk Delivery Zones (ADR-0039), each with the Districts it covers. */
        deliveryZones: DeliveryZone[];
        /** Every published Product, in catalogue order, the unavailable ones among them. */
        products: CartProduct[];
    };
    /** What the browser's retail Cart is priced from (ADR-0034); money in minor units. */
    retailPricing: {
        /** The retail Delivery Zones (ADR-0039), each with its threshold for free delivery. */
        deliveryZones: DeliveryZone[];
        /** Every Pack of every published Product, in catalogue order; a Product with none sends none. */
        packs: CartPack[];
    };
    /** Every open Cart (ADR-0038), in the Merchant's order, each with its Page's link. */
    carts: OpenCart[];
    /** Every closed Cart, by name: offered to nobody, named only where this browser still keeps Contents in it. */
    closedCarts: { id: number; name: string; kind: CartKind }[];
    /** The Carts the store opened with, deleted or not: what the browser held before Carts were named moves into them, once. */
    startingCarts: { bulk: number | null; retail: number | null };
    /** The Carts the header's খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন buttons open; null where that Cart is closed and its button is not drawn. */
    headerCarts: { retail: HeaderCart | null; bulk: HeaderCart | null };
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
