/**
 * The props every storefront response shares, as `HandleInertiaRequests`
 * sends them.
 */

import type { CartProduct } from '@/lib/cart';
import type { DeliveryBand } from '@/lib/pricing';

export type MenuLink = {
    label: string;
    href: string;
    external: boolean;
    note: string | null;
};

export type HeaderItem = {
    label: string;
    href: string | null;
    external: boolean;
    children: MenuLink[];
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
        };
    };
    paymentMethods: string[];
    links: {
        bulkOrder: string;
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
    seo: Seo;
    /** How many minor units make one taka; every price is sent in minor units. */
    minorUnitsPerTaka: number;
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}
