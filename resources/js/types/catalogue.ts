/**
 * Products and Categories as `App\Support\Storefront\CatalogueProps` sends
 * them, key for key. Every price is in minor units, as the pricing module
 * reads it; the shared `minorUnitsPerTaka` turns one into taka for display.
 */

import type { Tier } from '@/lib/pricing';

/** A Category Theme's ten colours, as `App\Enums\CategoryTheme::tokens()` names them. */
export type CategoryTheme = {
    text: string;
    tint: string;
    panel: string;
    panelText: string;
    panelSubtext: string;
    accent: string;
    pillBackground: string;
    pillText: string;
    tickBackground: string;
    line: string;
};

export type CategoryReference = {
    id: number;
    name: string;
    slug: string;
    href: string;
    theme: CategoryTheme;
};

/**
 * A Product sold পাইকারি, with its MRP and Tiers, or one sold only খুচরা,
 * with neither (ADR-0036): it is priced by its Packs, from `retailPricing`.
 * The bulk side only ever lists the first kind.
 */
export type ProductCard = BulkProductCard | RetailOnlyProductCard;

export type BulkProductCard = ProductCardBase & {
    isSoldBulk: true;
    /** What every discount shown is measured from; no Tier Price exceeds it. */
    mrp: number;
    /** The Product's own Tiers, in order; the first's price is the most it charges. */
    tiers: Tier[];
};

export type RetailOnlyProductCard = ProductCardBase & {
    isSoldBulk: false;
    mrp: null;
    tiers: [];
};

type ProductCardBase = {
    id: number;
    name: string;
    href: string;
    shortDescription: string | null;
    imageUrl: string | null;
    badge: string | null;
    /** The grade as written ("৪+") and the bars of five it fills; null when the Merchant left it blank. */
    strength: Strength | null;
    isAvailable: boolean;
    brand: { id: number; name: string };
    category: CategoryReference;
    tagIds: number[];
};

export type Strength = { grade: string; bars: number };

export type ProductDetail = ProductCard & {
    descriptionHtml: string | null;
    /** In order; the first is the cover. */
    gallery: string[];
    teaType: string | null;
    grade: string | null;
    particleSize: string | null;
    liquorColour: string | null;
    liquorBody: string | null;
    aroma: string | null;
    infusion: string | null;
    cupsPerKilogram: string | null;
    packageWeight: string | null;
    /** The Merchant's own, for this Product alone, drawn under অন্যান্য. */
    customCharacteristics: { label: string; value: string }[];
    bestFor: string[];
    notBestFor: string | null;
    /** In order; numbered on the page. */
    brewingSteps: string[];
    businessTips: string[];
    /** In order; each a heading and the line explaining it. */
    storageTips: { heading: string; explanation: string }[];
};

export type CategoryDetail = CategoryReference & {
    note: string | null;
    qualityHeadline: string | null;
    about: string | null;
    whyPoints: string[];
    productCount: number;
    /** From the cheapest last Tier to the dearest first Tier of its Products sold পাইকারি; null with none. */
    priceRange: { low: number; high: number } | null;
};

/** One of the live search dropdown's matches. */
export type SearchSuggestion = {
    name: string;
    href: string;
    imageUrl: string | null;
    categoryName: string;
    /** Null for a Product sold only খুচরা, which has no Tiers. */
    firstTierPrice: number | null;
};
