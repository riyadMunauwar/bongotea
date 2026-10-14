/**
 * A Page's Blocks as `PageBlocks::forStorefront()` sends them: each kind's
 * props are what its PHP definition's `props()` returns, key for key.
 */

import type { BulkProductCard, CategoryDetail, CategoryReference, CategoryTheme, ProductCard } from '@/types/catalogue';

export type BlockButton = { label: string; href: string };

export type HeroCarouselProps = {
    layout: 'carousel' | 'banner';
    slides: {
        eyebrow: string | null;
        title: string;
        text: string | null;
        imageUrl: string | null;
        primary: BlockButton | null;
        secondary: BlockButton | null;
        /** Further buttons, each drawn like the second. */
        more: BlockButton[];
    }[];
};

export type PromiseStripProps = {
    items: { icon: string | null; title: string; text: string | null }[];
};

export type HowToOrderProps = {
    eyebrow: string | null;
    heading: string | null;
    text: string | null;
    steps: { title: string; text: string | null; chip: string | null }[];
    cta: BlockButton | null;
    videoId: string | null;
    showHotline: boolean;
};

export type VideoReviewsProps = {
    eyebrow: string | null;
    heading: string | null;
    hint: string | null;
    reviews: {
        name: string;
        place: string | null;
        product: string | null;
        duration: string | null;
        quote: string | null;
        videoId: string | null;
        imageUrl: string | null;
    }[];
};

export type TextReviewsProps = {
    heading: string | null;
    ratingSummary: string | null;
    note: string | null;
    reviews: {
        name: string;
        place: string | null;
        product: string | null;
        text: string;
        verified: boolean;
    }[];
};

export type StoryProps = {
    eyebrow: string | null;
    quote: string | null;
    bodyHtml: string | null;
};

export type JourneyProps = {
    eyebrow: string | null;
    heading: string | null;
    steps: { title: string; text: string | null; imageUrl: string | null }[];
};

export type ValueCardsProps = {
    eyebrow: string | null;
    heading: string | null;
    cards: {
        icon: string | null;
        title: string;
        text: string | null;
        button: BlockButton | null;
    }[];
};

export type ChipsRowProps = {
    heading: string | null;
    /** A chip with no link is a plain word. */
    chips: { label: string; href: string | null }[];
};

export type CallToActionBandProps = {
    heading: string | null;
    text: string | null;
    primary: BlockButton | null;
    secondary: BlockButton | null;
    more: BlockButton[];
};

export type TableProps = {
    eyebrow: string | null;
    heading: string | null;
    text: string | null;
    columns: string[];
    /** Each row holds exactly one cell per column. */
    rows: { cells: string[] }[];
    footnote: string | null;
    button: BlockButton | null;
};

export type InfoCardsProps = {
    eyebrow: string | null;
    heading: string | null;
    text: string | null;
    columns: 1 | 2 | 3;
    cards: {
        badge: string | null;
        anchor: string | null;
        eyebrow: string | null;
        title: string;
        subtitle: string | null;
        text: string | null;
        lists: { label: string | null; items: string[] }[];
        note: { label: string | null; text: string } | null;
        button: BlockButton | null;
    }[];
};

export type RecipeCardsProps = {
    eyebrow: string | null;
    heading: string | null;
    text: string | null;
    recipes: {
        name: string;
        englishName: string | null;
        anchor: string | null;
        tea: string | null;
        serves: string | null;
        time: string | null;
        taste: string | null;
        ingredients: { item: string; quantity: string | null }[];
        steps: string[];
        tips: { label: string | null; text: string }[];
    }[];
};

export type ContactCardsProps = {
    whatsappNote: string | null;
    emailNote: string | null;
    raised: boolean;
};

export type MapAndAddressProps = {
    embedUrl: string | null;
    hours: { days: string; hours: string }[];
};

export type FaqProps = {
    eyebrow: string | null;
    heading: string | null;
    items: { question: string; answer: string }[];
};

export type CategoryTilesProps = {
    heading: string | null;
    allLink: BlockButton | null;
    tiles: {
        id: number;
        name: string;
        note: string | null;
        href: string;
        theme: CategoryTheme;
        productCount: number;
        /** The lowest price any of its Products sold পাইকারি reaches at its last Tier, in minor units; null with none. */
        lowestPrice: number | null;
    }[];
};

export type CategoryPanelsProps = {
    eyebrow: string | null;
    heading: string | null;
    tags: { id: number; name: string }[];
    /** Empty while only one Brand has published Products, so no Brand filter is drawn. */
    brands: { id: number; name: string }[];
    categories: (CategoryDetail & { products: ProductCard[] })[];
};

export type ProductRailProps = {
    eyebrow: string | null;
    heading: string | null;
    link: BlockButton | null;
    products: ProductCard[];
};

/** A Category with a published Product sold পাইকারি, and its cards, as the পাইকারি Cart's Blocks list every tea. */
export type CartCategory = CategoryReference & {
    note: string | null;
    products: BulkProductCard[];
};

export type BulkOrderFormProps = {
    heading: string | null;
    text: string | null;
    quickChips: boolean;
    showTierList: boolean;
    categories: CartCategory[];
};

export type PriceCalculatorProps = {
    heading: string | null;
    text: string | null;
    categories: CartCategory[];
};

export type PriceMatrixProps = {
    heading: string | null;
    text: string | null;
    categories: CartCategory[];
};

/** Only wording: the bands are the shared `pricing.deliveryBands`. */
export type DeliveryChartProps = {
    heading: string | null;
    text: string | null;
    /** Whether the Merchant takes Cash on Delivery, so the chart may say so. */
    offersCashOnDelivery: boolean;
};

/** Where an Order stands as its Customer sees it, worked out by the server. */
export type OrderProgress = 'received' | 'confirmed' | 'sent' | 'delivered' | 'cancelled';

export type PacketBasketProps = {
    heading: string | null;
    text: string | null;
};

export type MyOrdersProps = {
    heading: string | null;
    text: string | null;
    /** The number this browser is Signed in as, as dialled at home; null for a stranger. */
    phone: string | null;
    /** Newest first; empty for a stranger. Totals in minor units. */
    orders: {
        number: string;
        /** The Merchant's day it was placed, as YYYY-MM-DD. */
        placedOn: string;
        /** A Pack line names its weight and count instead of Kilograms. */
        lines: ({ name: string; kilograms: number } | { name: string; packGrams: number; count: number })[];
        kilograms: number;
        total: number;
        progress: OrderProgress;
    }[];
};

export type ContactFormProps = {
    heading: string | null;
    text: string | null;
    /** The chips a question's topic is picked from; the first is picked to begin with. */
    topics: string[];
};

export type BlockPropsByType = {
    hero_carousel: HeroCarouselProps;
    promise_strip: PromiseStripProps;
    how_to_order: HowToOrderProps;
    video_reviews: VideoReviewsProps;
    text_reviews: TextReviewsProps;
    story: StoryProps;
    journey: JourneyProps;
    value_cards: ValueCardsProps;
    info_cards: InfoCardsProps;
    table: TableProps;
    recipe_cards: RecipeCardsProps;
    chips_row: ChipsRowProps;
    cta_band: CallToActionBandProps;
    contact_cards: ContactCardsProps;
    map: MapAndAddressProps;
    faq: FaqProps;
    category_tiles: CategoryTilesProps;
    category_panels: CategoryPanelsProps;
    product_rail: ProductRailProps;
    bulk_order_form: BulkOrderFormProps;
    price_calculator: PriceCalculatorProps;
    price_matrix: PriceMatrixProps;
    delivery_chart: DeliveryChartProps;
    my_orders: MyOrdersProps;
    packet_basket: PacketBasketProps;
    contact_form: ContactFormProps;
};

export type BlockType = keyof BlockPropsByType;

export type PageBlock = {
    [Type in BlockType]: {
        type: Type;
        anchor: string | null;
        hideOnMobile: boolean;
        hideOnDesktop: boolean;
        props: BlockPropsByType[Type];
    };
}[BlockType];

export type PageProps = {
    page: {
        title: string;
        /** Home draws the Cart's summary bar while the Cart holds anything. */
        isHome: boolean;
        blocks: PageBlock[];
    };
};
